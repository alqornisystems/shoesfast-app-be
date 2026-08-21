<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Send;
use App\Models\Service;
use App\Models\Treatment;
use App\Services\PickupZoneService;
use App\Support\Base64Image;
use App\Support\ItemChecklist;
use App\Support\OrderProgress;
use App\Support\ServiceDay;
use App\Support\WarrantyWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Label untuk pelanggan. Admin panel memberi label 3 = "Dibatalkan"
     * (order-client.tsx:81), padahal 2.448 pesanan berstatus 3 lunas dibayar.
     * Pembatalan sebenarnya memakai is_deleted, bukan status.
     */
    private const STATUS_LABELS = [
        0 => 'Menunggu diproses',
        1 => 'Sedang dikerjakan',
        2 => 'Siap diambil',
        3 => 'Selesai',
    ];

    /**
     * Cakupan cabang dipaksakan eksplisit, tidak menumpang BranchScoped.
     * BranchContext membaca Auth::user()->projects_id, dan menggantungkan
     * isolasi data pelanggan pada perilaku yang dirancang untuk staf adalah
     * ketergantungan yang tidak dijanjikan siapa pun.
     */
    /**
     * Pesanan milik pelanggan ini: pemilik DAN cabang.
     *
     * Pemeriksaan cabang sempat dilepas karena dikira menyembunyikan riwayat pelanggan
     * yang pernah memesan di cabang lain. Dugaan itu diuji ke data nyata dan SALAH —
     * nol pesanan yang cabangnya berbeda dari cabang pelanggannya. Jadi melepasnya tidak
     * memperbaiki apa pun dan hanya melonggarkan penjaga yang dipasang dengan sengaja.
     *
     * Yang perlu diketahui kalau suatu hari benar terjadi: memindahkan pelanggan antar
     * cabang lewat panel admin akan membuat seluruh riwayat pesanannya lenyap dari portal
     * tanpa jejak. Kalau itu muncul, di sinilah tempat memperbaikinya.
     */
    private function scopedOrders(Customer $customer)
    {
        return Order::withoutGlobalScope('branch')
            ->where('customers_id', $customer->id)
            ->where('projects_id', $customer->projects_id);
    }

    // GET /api/customer/orders
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();
        $perPage = min((int) $request->input('per_page', 10), 50);

        $paginator = $this->scopedOrders($customer)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage);

        // Hitung barang siap per pesanan dalam SATU query, bukan membangun OrderProgress
        // untuk tiap baris daftar. Yang dibutuhkan di sini cuma angkanya; riwayat lengkap
        // dan posisi barang menunggu sampai pesanannya benar-benar dibuka.
        $rekap = OrderItem::withoutGlobalScope('branch')
            ->selectRaw('orders_id, COUNT(*) as total, SUM(status = 2) as siap')
            ->whereIn('orders_id', $paginator->getCollection()->pluck('id'))
            ->where('status', '!=', 3)
            ->groupBy('orders_id')
            ->get()
            ->keyBy('orders_id');

        $paginator->getCollection()->transform(function (Order $order) use ($rekap) {
            $baris = $rekap->get($order->id);
            $total = (int) ($baris->total ?? 0);
            $siap = (int) ($baris->siap ?? 0);

            return [
                'id' => $order->id,
                'code' => $order->code,
                'date' => $order->date,
                'status' => (int) $order->status,
                // "Siap diambil" hanya kalau SEMUA barangnya siap. Satu pesanan bisa
                // berisi tiga pasang sepatu yang selesai di hari berbeda, dan label
                // tunggal menyembunyikan bahwa yang pertama sudah boleh diambil.
                'status_label' => match (true) {
                    $total > 0 && $siap === $total => 'Siap diambil',
                    $siap > 0 => "Sebagian siap diambil ({$siap} dari {$total})",
                    default => self::STATUS_LABELS[(int) $order->status] ?? 'Tidak diketahui',
                },
                'items_ready' => $siap,
                'items_total' => $total,
                'total_price' => (int) $order->total_price,
            ];
        });

        return response()->json($paginator);
    }

    /**
     * GET /api/customer/items
     *
     * Barang yang pernah dititipkan pelanggan ini, terbaru dulu.
     *
     * Alasannya sederhana: orang menitipkan sepatu yang sama berulang kali, dan
     * mengetik ulang "Nike Air Force 1 putih" tiap kali adalah pekerjaan yang tidak
     * menghasilkan apa pun — sekaligus sumber salah ketik yang membuat dua baris di
     * database terlihat seperti dua sepatu berbeda.
     *
     * Fotonya ikut karena itulah yang benar-benar dikenali orang. Nama yang ditulis
     * petugas ("AF1 putih", "sepatu putih") tidak selalu sama dengan yang ada di kepala
     * pemiliknya; fotonya selalu.
     */
    public function items(Request $request): JsonResponse
    {
        $customer = $request->user();

        $riwayat = OrderItem::withoutGlobalScope('branch')
            ->whereIn('orders_id', $this->scopedOrders($customer)->select('id'))
            ->orderByDesc('id')
            // Dipindai lebih dalam daripada yang ditampilkan: pengelompokan baru
            // benar kalau titipan berulang atas barang yang sama memang bertemu satu
            // sama lain. Memindai 24 baris lalu mengelompokkannya berarti pelanggan
            // yang sebulan lalu menitipkan enam pasang sekaligus kehilangan sepatu
            // lamanya dari daftar.
            ->limit(120)
            ->get();

        // Satu baris per BARANG, bukan per titipan. Kuncinya nama + jenis: itulah yang
        // membuat sepasang sepatu yang sudah lima kali dicuci tetap satu barang, bukan
        // lima. Baris terbaru yang mewakili — fotonya paling mendekati rupa barang
        // sekarang, dan kelengkapannya paling mendekati yang biasa ikut.
        $grup = $riwayat
            ->groupBy(fn (OrderItem $item) => mb_strtolower(trim((string) $item->name)).'|'.(int) $item->type)
            ->map(function ($titipan) {
                /** @var OrderItem $terbaru */
                $terbaru = $titipan->first();

                return [
                    // Dipakai klien untuk menunjuk barang ini saat memesan lagi. Foto
                    // dan jalurnya disalin di server dari baris ini, jadi klien tidak
                    // perlu — dan tidak boleh — mengirim balik URL foto apa pun.
                    'id' => $terbaru->id,
                    'name' => $terbaru->name,
                    'type' => (int) $terbaru->type,
                    'photo' => Base64Image::url($terbaru->photo),
                    'kelengkapan' => $this->checkboxFlags($terbaru),
                    'times' => $titipan->count(),
                    'last_at' => $terbaru->created_at,
                ];
            })
            ->sortByDesc('last_at')
            ->take(20)
            ->values();

        return response()->json(['data' => $grup]);
    }

    /**
     * Centang kelengkapan sebagai deretan boolean, panjangnya mengikuti jenis.
     *
     * Bedanya dengan kelengkapan(): yang itu mengembalikan LABEL yang tercentang untuk
     * dibaca manusia, yang ini bentuk mentahnya untuk mengisi ulang formulir.
     *
     * @return list<bool>
     */
    private function checkboxFlags(OrderItem $item): array
    {
        return ItemChecklist::flags($item);
    }

    // GET /api/customer/orders/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $order = $this->scopedOrders($customer)->find($id);

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan'], 404);
        }

        $items = OrderItem::withoutGlobalScope('branch')
            ->with([
                'treatments' => fn ($q) => $q->withoutGlobalScope('branch'),
                'treatments.service',
                // Nama teknisi dan mitra ikut dimuat: "siapa yang mengerjakan sepatu
                // saya" adalah pertanyaan yang selama ini hanya bisa dijawab lewat
                // WhatsApp, dan jawabannya sudah ada di database sejak awal.
                'treatments.user',
                'treatments.partnership',
            ])
            ->where('orders_id', $order->id)
            ->get();

        $order->loadMissing('project');
        $progress = new OrderProgress($order, $items);
        $ringkasan = $progress->summary();

        $totalPaid = (int) Payment::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)
            ->sum('nominal');

        return response()->json([
            'id' => $order->id,
            'code' => $order->code,
            'date' => $order->date,
            'status' => (int) $order->status,
            // Label DITURUNKAN dari barang-barangnya, bukan dibaca dari orders.status.
            // "Siap diambil" untuk pesanan yang dua dari tiga barangnya masih di bengkel
            // adalah janji yang tidak dipenuhi di kasir.
            // Pesanan tanpa barang belum punya apa pun untuk diturunkan, jadi label
            // lamanya yang dipakai — termasuk pemetaan status 3 ke "Selesai", karena
            // 2.448 pesanan berstatus 3 justru lunas dibayar, bukan dibatalkan.
            'status_label' => $ringkasan['total'] > 0
                ? $ringkasan['label']
                : (self::STATUS_LABELS[(int) $order->status] ?? 'Tidak diketahui'),
            'progress' => $ringkasan,
            // Sama seperti invoice(): harga yang belum ditentukan dikirim null, bukan 0.
            // Nol membuat sisa tagihan ikut nol, dan layar membacanya sebagai lunas.
            'total_price' => $order->total_price === null || (int) $order->total_price === 0
                ? null
                : (int) $order->total_price,
            'total_paid' => $totalPaid,
            'credit' => $order->total_price === null || (int) $order->total_price === 0
                ? null
                : (int) $order->total_price - $totalPaid,
            'pickup_address' => $order->pickup_address,
            'pickup_maps' => $order->pickup_maps,
            'items' => $items->map(fn (OrderItem $item) => $this->presentItem($item, $order, $progress))->values(),
            'timeline' => $this->timeline($order, $items),
            'tracking' => $this->pelacakan($order),
        ]);
    }

    /**
     * Tautan lacak kurir untuk pesanan ini, kalau memang ada tugas yang sedang jalan.
     *
     * Tokennya sudah diterbitkan saat kurir menekan "berangkat"; yang kurang selama ini
     * cuma jalan pelanggan menemukannya. Tautan itu dikirim lewat WhatsApp oleh kurir —
     * dan pesan WhatsApp tenggelam. Halaman pesanannya sendiri tempat paling wajar
     * mencarinya.
     *
     * null kalau tidak ada tugas berjalan, tokennya sudah dicabut, atau masa berlakunya
     * lewat. Tidak apa-apa mengembalikan token ke pelanggan ini: pesanannya memang
     * miliknya, dan kepemilikan itu sudah dipastikan di atas.
     */
    private function pelacakan(Order $order): ?array
    {
        $send = Send::withoutGlobalScopes()
            ->where('orders_id', $order->id)
            ->where('is_deleted', 0)
            ->where('status', Send::STATUS_BERJALAN)
            ->whereNotNull('tracking_token')
            ->where('tracking_expires_at', '>', time())
            ->orderByDesc('id')
            ->first();

        if (! $send) {
            return null;
        }

        return [
            'token' => $send->tracking_token,
            // 0 = jemput, 1 = antar. Kalimat di layar berbeda: yang satu kurir datang
            // mengambil, yang satu mengantar pulang.
            'type' => (int) $send->type,
            'expires_at' => (int) $send->tracking_expires_at,
        ];
    }

    // POST /api/customer/orders
    public function store(Request $request, PickupZoneService $pickupZone): JsonResponse
    {
        $customer = $request->user();

        // Pelanggan boleh memilih LAYANAN, tapi tidak pernah HARGA. Tidak ada
        // 'price' di aturan ini dan tidak ada satu pun harga yang dibaca dari badan
        // permintaan — semuanya diambil ulang dari tabel services di server. Kalau
        // tidak, siapa pun yang bisa menyunting satu request bisa memesan bag spa
        // seharga nol rupiah.
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'integer', 'in:0,1,2'],
            'items.*.name' => ['required', 'string', 'max:100'],
            'items.*.checkbox' => ['nullable', 'array'],
            'items.*.checkbox.*' => ['boolean'],
            'items.*.note' => ['nullable', 'string'],
            // Data URL hasil kamera ponsel, sudah dikecilkan klien ke 1080 px.
            // Batasnya longgar karena yang tersimpan di kolom cuma JALUR berkas —
            // gambarnya sendiri ditulis ke disk oleh Base64Image. Tetap dibatasi:
            // tanpa atap, satu request bisa menyuruh server mendekode gambar 50 MB.
            'items.*.photo' => ['nullable', 'string', 'max:1300000'],
            // Menunjuk barang yang pernah dititipkan, supaya fotonya disalin di sini
            // tanpa pelanggan memotret ulang. Kepemilikannya diperiksa, bukan
            // dipercaya: id milik orang lain akan diabaikan.
            'items.*.from_item_id' => ['nullable', 'integer'],
            'items.*.services' => ['nullable', 'array', 'max:10'],
            'items.*.services.*' => ['integer', 'exists:services,id'],
            'pickup.method' => ['required', 'in:jemput,antar_sendiri,ekspedisi'],
            'pickup.date' => ['nullable', 'date'],
        ]);

        $method = $validated['pickup']['method'];
        $freePickup = $pickupZone->evaluate($customer);

        // Jarak tidak pernah menolak. Yang menolak adalah ketiadaan titik
        // peta pada metode jemput: kurir butuh tujuan, dan pelanggan selalu
        // bisa memilih antar sendiri atau ekspedisi sementara itu.
        if ($method === 'jemput' && $freePickup['reason'] === 'tanpa_koordinat') {
            return response()->json([
                'message' => 'Titik peta belum diisi. Lengkapi alamat di profil dulu.',
            ], 422);
        }

        // Kurir tidak berangkat hari Minggu dan tanggal merah. Menerima tanggalnya
        // diam-diam berarti menjanjikan penjemputan yang tidak akan terjadi, dan
        // pelanggan baru tahu saat menunggu seharian tanpa ada yang datang.
        if (! empty($validated['pickup']['date'])) {
            $tanggal = strtotime($validated['pickup']['date']);
            $tutup = ServiceDay::closedReason($tanggal);

            if ($tutup !== null) {
                return response()->json([
                    'message' => $tutup.'. Pilih hari lain, misalnya '
                        .date('j F Y', ServiceDay::nextOpen($tanggal + 86400)).'.',
                    'errors' => ['pickup.date' => [$tutup.', kurir tidak berangkat.']],
                ], 422);
            }
        }

        // Pengerjaan paling cepat dimulai saat barangnya sampai. Kalau pelanggan
        // memilih tanggal jemput, itulah tanggalnya; kalau tidak, hari ini.
        $mulaiDari = isset($validated['pickup']['date'])
            ? strtotime($validated['pickup']['date'])
            : time();

        $order = DB::transaction(function () use ($customer, $validated, $method, $mulaiDari) {
            $order = Order::withoutGlobalScope('branch')->create([
                'projects_id' => $customer->projects_id,
                'customers_id' => $customer->id,
                'code' => $this->generateCode(),
                'date' => time(),
                'total_price' => 0,
                'total_discount' => 0,
                'status' => 0,
                'source' => 1,
                'pickup_address' => $method === 'jemput' ? $customer->address : null,
                'pickup_maps' => $method === 'jemput' ? $customer->maps : null,
            ]);

            foreach ($validated['items'] as $itemData) {
                $item = OrderItem::withoutGlobalScope('branch')->create([
                    'projects_id' => $customer->projects_id,
                    'orders_id' => $order->id,
                    'name' => $itemData['name'],
                    'type' => $itemData['type'],
                    // Tetap 0. Harga barang menunggu petugas memeriksanya, dan selama
                    // total pesanan masih 0 portal menandainya "belum ada tagihan"
                    // alih-alih menagih angka yang belum tentu jadi angka akhirnya.
                    'price' => 0,
                    'discount' => 0,
                    'status' => 0,
                    'note' => $itemData['note'] ?? null,
                    'photo' => $this->simpanFotoBarang($itemData['photo'] ?? null, $order->id)
                        ?? $this->fotoBarangLama($customer, $itemData['from_item_id'] ?? null),
                    'checkbox' => $this->serializeCheckbox($itemData),
                ]);

                $this->createTreatments($item, $itemData['services'] ?? [], $customer->projects_id, $mulaiDari);
            }

            if ($method === 'jemput') {
                Send::withoutGlobalScope('branch')->create([
                    'projects_id' => $customer->projects_id,
                    'orders_id' => $order->id,
                    // 0 berarti kurir belum ditugaskan; kolomnya NOT NULL di
                    // produksi sehingga tidak bisa dibiarkan kosong.
                    'users_id' => 0,
                    'date' => isset($validated['pickup']['date'])
                        ? strtotime($validated['pickup']['date'])
                        : time(),
                    'type' => 0,
                    'status' => 0,
                ]);
            }

            return $order;
        });

        return response()->json([
            'id' => $order->id,
            'code' => $order->code,
            'free_pickup' => $freePickup,
        ], 201);
    }

    /**
     * Foto barang dari pelanggan.
     *
     * Folder dan pemroses yang sama dengan unggahan admin, jadi keduanya menghasilkan
     * jalur berbentuk sama dan pembaca mana pun tidak perlu tahu siapa yang mengunggah.
     *
     * Nilai yang bukan data URL diabaikan, bukan disimpan apa adanya: satu-satunya
     * pengirim endpoint ini adalah formulir pesanan baru yang tidak punya foto lama
     * untuk dikirim balik, jadi apa pun selain data URL adalah salah kirim.
     */
    private function simpanFotoBarang(?string $foto, int $orderId): ?string
    {
        if (empty($foto) || ! str_starts_with($foto, 'data:image/')) {
            return null;
        }

        return Base64Image::store($foto, 'orders_items', 'item-'.$orderId.'-'.uniqid());
    }

    /**
     * Jalur foto milik barang yang pernah dititipkan pelanggan INI.
     *
     * Dipakai saat pelanggan memesan lagi untuk barang yang sama: fotonya tidak perlu
     * diambil ulang, dan klien tidak perlu mengirim balik URL apa pun. Yang dikirim
     * cuma id barang lama, lalu server yang membacanya — jadi tidak ada jalan bagi
     * siapa pun untuk menyisipkan alamat gambar sembarangan ke dalam pesanan.
     *
     * Kepemilikan diperiksa lewat scopedOrders(): id milik pelanggan lain menghasilkan
     * null, bukan foto orang lain.
     */
    private function fotoBarangLama(Customer $customer, ?int $itemId): ?string
    {
        if (! $itemId) {
            return null;
        }

        return OrderItem::withoutGlobalScope('branch')
            ->where('id', $itemId)
            ->whereIn('orders_id', $this->scopedOrders($customer)->select('id'))
            ->value('photo');
    }

    /**
     * Layanan pilihan pelanggan jadi baris treatment — satu baris per layanan,
     * persis seperti yang dibuat admin panel lewat form barang pesanan.
     *
     * Harganya dibaca dari tabel services, BUKAN dari permintaan. Yang tersimpan
     * adalah harga saat pesanan dibuat: kalau tarif naik bulan depan, pesanan ini
     * tidak boleh ikut naik diam-diam.
     *
     * Jadwalnya dirantai memakai `estimation` tiap layanan seperti di admin, tapi
     * mulainya dari tanggal barang direncanakan sampai — bukan dari sekarang.
     * Menjadwalkan pengerjaan sebelum barangnya ada di bengkel membuat antrean
     * teknisi terlihat penuh oleh pekerjaan yang belum bisa disentuh.
     *
     * @param  list<int>  $serviceIds
     */
    private function createTreatments(OrderItem $item, array $serviceIds, ?int $projectId, int $mulaiDari): void
    {
        $sebelumnya = null;

        foreach ($serviceIds as $serviceId) {
            $service = Service::withoutGlobalScope('branch')->find($serviceId);

            if (! $service) {
                continue;
            }

            $mulai = $sebelumnya === null ? $mulaiDari : strtotime('+1 day', $sebelumnya);
            $selesai = strtotime('+'.(int) $service->estimation.' day', $mulai);

            Treatment::withoutGlobalScope('branch')->create([
                'projects_id' => $projectId,
                'orders_items_id' => $item->id,
                'services_id' => $service->id,
                'price' => (int) $service->price,
                'date_start' => $mulai,
                'date_end' => $selesai,
                // 0 = belum dikerjakan, dan users_id sengaja dibiarkan kosong:
                // teknisinya ditentukan admin, bukan pelanggan.
                'status' => 0,
            ]);

            $sebelumnya = $selesai;
        }
    }

    /**
     * Format cermin admin panel: INV + tahun bulan + urutan 4 digit.
     */
    private function generateCode(): string
    {
        $prefix = 'INV'.date('Ym');

        $last = Order::withoutGlobalScope('branch')
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');

        $sequence = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function serializeCheckbox(array $itemData): string
    {
        return ItemChecklist::serialize((int) $itemData['type'], $itemData['checkbox'] ?? []);
    }

    // GET /api/customer/orders/{id}/invoice
    public function invoice(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $order = $this->scopedOrders($customer)->find($id);

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan'], 404);
        }

        $payments = Payment::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)
            ->orderBy('date')
            ->get();

        $totalPaid = (int) $payments->sum('nominal');

        // Harga BOLEH belum ada: pesanan portal lahir tanpa harga dan petugas
        // menentukannya setelah barang diperiksa. Memaksa null jadi 0 membuat
        // credit ikut 0, dan pesanan yang belum ditagih dilaporkan LUNAS.
        $belumBerharga = $order->total_price === null || (int) $order->total_price === 0;
        $totalPrice = $belumBerharga ? null : (int) $order->total_price;
        $credit = $belumBerharga ? null : $totalPrice - $totalPaid;

        // Disalin dari PaymentController::index dan PublicInvoiceController
        // supaya ketiganya tidak pernah berbeda angka untuk pesanan yang sama.
        $dueDate = strtotime(date('Y-m-d', strtotime(date('Y-m-d', $order->date).' +3 days')));

        return response()->json([
            'code' => $order->code,
            'date' => $order->date,
            'due_date' => $dueDate,
            'payment_status' => $belumBerharga
                ? 'unpriced'
                : ($credit <= 0 ? 'paid' : ($totalPaid > 0 ? 'partial' : 'unpaid')),
            'total_price' => $totalPrice,
            'total_paid' => $totalPaid,
            'credit' => $credit,
            'payments' => $payments->map(fn (Payment $payment) => [
                'date' => $payment->date,
                'nominal' => (int) $payment->nominal,
                'note' => $payment->note,
            ])->values(),
        ]);
    }

    private function presentItem(OrderItem $item, ?Order $order = null, ?OrderProgress $progress = null): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'type' => (int) $item->type,
            'photo' => \App\Support\Base64Image::url($item->photo),
            'kelengkapan' => $this->kelengkapan($item),
            'note' => $item->note,
            // Harga per barang, supaya rincian tagihan bisa menunjukkan ASAL angkanya.
            // Total tanpa rincian adalah angka yang harus dipercaya begitu saja, dan
            // pertanyaan pertama tiap orang yang melihat tagihan adalah "ini dari mana".
            // null selama petugas belum menentukannya — 0 akan terbaca sebagai gratis.
            'price' => (int) $item->price === 0 ? null : (int) $item->price,
            'discount' => (int) $item->discount,
            // Kelayakan klaim garansi ikut dikirim supaya portal tidak perlu menebak
            // aturannya sendiri. Tombol yang muncul lalu ditolak saat ditekan lebih
            // buruk daripada tombol yang tidak pernah muncul.
            'claim' => $order ? WarrantyWindow::status($order, $item) : null,
            // Keadaan, posisi, tagihan, dan riwayat barang ini — semuanya per barang.
            'progress' => $progress?->item($item),
            'treatments' => $item->treatments->map(fn ($treatment) => [
                'name' => $treatment->service?->name,
                'price' => (int) $treatment->price === 0 ? null : (int) $treatment->price,
                'status' => (int) $treatment->status,
                'date_start' => $treatment->date_start,
                'done_at' => $treatment->done_at,
            ])->values(),
        ];
    }

    /**
     * checkbox disimpan sebagai deretan boolean berkoma menurut urutan tetap.
     * Daftarnya harus persis sama dengan order-form-client.tsx di admin panel,
     * kalau tidak kedua aplikasi berbeda tafsir atas baris yang sama.
     */
    private function kelengkapan(OrderItem $item): array
    {
        return ItemChecklist::checked($item);
    }

    private function timeline(Order $order, $items): array
    {
        $timeline = [[
            'key' => 'dibuat',
            'label' => 'Pesanan dibuat',
            'date' => $order->date,
            'done' => true,
        ]];

        $pickup = Send::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)->where('type', 0)
            ->orderBy('date')->first();

        if ($pickup) {
            $timeline[] = [
                'key' => 'dijemput',
                'label' => 'Dijemput kurir',
                'date' => $pickup->date,
                'done' => (int) $pickup->status === 1,
            ];
        }

        $treatments = $items->flatMap(fn (OrderItem $item) => $item->treatments);
        if ($treatments->isNotEmpty()) {
            $timeline[] = [
                'key' => 'dikerjakan',
                'label' => 'Pengerjaan',
                'date' => $treatments->min('date_start'),
                'done' => $treatments->every(fn ($treatment) => (int) $treatment->status === 2),
            ];
        }

        $delivery = Send::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)->where('type', 1)
            ->orderBy('date')->first();

        if ($delivery) {
            $timeline[] = [
                'key' => 'diantar',
                'label' => 'Diantar kurir',
                'date' => $delivery->date,
                'done' => (int) $delivery->status === 1,
            ];
        }

        $timeline[] = [
            'key' => 'selesai',
            'label' => 'Selesai',
            'date' => null,
            'done' => (int) $order->status === 3,
        ];

        return $timeline;
    }
}
