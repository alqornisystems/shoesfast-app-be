<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Send;
use App\Services\PickupZoneService;
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

    private const SHOE_CHECKLIST = ['Tali Sepatu', 'Kaos Kaki', 'Box Sepatu'];

    private const BAG_CHECKLIST = [
        'Dust Bag', 'Care Card/Card', 'Tali panjang', 'Tali pendek',
        'Tag Brand', 'Price tag', 'Receipt',
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

        $paginator->getCollection()->transform(fn (Order $order) => [
            'id' => $order->id,
            'code' => $order->code,
            'date' => $order->date,
            'status' => (int) $order->status,
            'status_label' => self::STATUS_LABELS[(int) $order->status] ?? 'Tidak diketahui',
            'total_price' => (int) $order->total_price,
        ]);

        return response()->json($paginator);
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
            ->with(['treatments' => fn ($q) => $q->withoutGlobalScope('branch'), 'treatments.service'])
            ->where('orders_id', $order->id)
            ->get();

        $totalPaid = (int) Payment::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)
            ->sum('nominal');

        return response()->json([
            'id' => $order->id,
            'code' => $order->code,
            'date' => $order->date,
            'status' => (int) $order->status,
            'status_label' => self::STATUS_LABELS[(int) $order->status] ?? 'Tidak diketahui',
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
            'items' => $items->map(fn (OrderItem $item) => $this->presentItem($item))->values(),
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

        // Tidak ada 'price' dan tidak ada 'services_id' di sini. Harga dan
        // layanan ditentukan admin saat barang diperiksa; endpoint ini tidak
        // boleh pernah membaca harga dari badan permintaan.
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'integer', 'in:0,1,2'],
            'items.*.name' => ['required', 'string', 'max:100'],
            'items.*.checkbox' => ['nullable', 'array'],
            'items.*.checkbox.*' => ['boolean'],
            'items.*.note' => ['nullable', 'string'],
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

        $order = DB::transaction(function () use ($customer, $validated, $method) {
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
                OrderItem::withoutGlobalScope('branch')->create([
                    'projects_id' => $customer->projects_id,
                    'orders_id' => $order->id,
                    'name' => $itemData['name'],
                    'type' => $itemData['type'],
                    'price' => 0,
                    'discount' => 0,
                    'status' => 0,
                    'note' => $itemData['note'] ?? null,
                    'checkbox' => $this->serializeCheckbox($itemData),
                ]);
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
        $size = ((int) $itemData['type'] === 1)
            ? count(self::BAG_CHECKLIST)
            : count(self::SHOE_CHECKLIST);

        $flags = $itemData['checkbox'] ?? [];

        $normalized = [];
        for ($index = 0; $index < $size; $index++) {
            $normalized[] = ! empty($flags[$index]) ? 'true' : 'false';
        }

        return implode(', ', $normalized);
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

    private function presentItem(OrderItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'type' => (int) $item->type,
            'photo' => $item->photo
                ? (filter_var($item->photo, FILTER_VALIDATE_URL) ? $item->photo : asset('storage/'.$item->photo))
                : null,
            'kelengkapan' => $this->kelengkapan($item),
            'note' => $item->note,
            'treatments' => $item->treatments->map(fn ($treatment) => [
                'name' => $treatment->service?->name,
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
        $labels = ((int) $item->type === 1) ? self::BAG_CHECKLIST : self::SHOE_CHECKLIST;

        if (! $item->checkbox) {
            return [];
        }

        $flags = array_map(fn ($value) => trim($value) === 'true', explode(',', $item->checkbox));

        $result = [];
        foreach ($labels as $index => $label) {
            if ($flags[$index] ?? false) {
                $result[] = $label;
            }
        }

        return $result;
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
