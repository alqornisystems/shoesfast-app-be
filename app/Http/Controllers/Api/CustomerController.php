<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Models\Guarantee;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    use \App\Http\Controllers\Concerns\DaftarTersortir;

    /** Kolom yang boleh dipakai mengurutkan daftar ini. */
    private const SORTIR_PELANGGAN = [
        'name' => 'customers.name',
        'phone' => 'customers.phone',
        'created_at' => 'customers.created_at',
        'last_order' => 'rb.pesanan_terakhir',
        'total_spent' => 'COALESCE(rb.total_belanja, 0)',
        'orders_count' => 'COALESCE(rb.jumlah_pesanan, 0)',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 25);
        $search = $request->get('search', '');
        $isMember = $request->get('is_member');

        $request->validate([
            'segment' => ['nullable', 'string', \Illuminate\Validation\Rule::in(array_keys(Customer::SEGMEN))],
            'tag' => ['nullable', 'string', 'max:50'],
        ]);

        $query = $this->pelangganCabang(
            Customer::with(['projects', 'tags'])->denganRingkasanBelanja()
        );

        // Filter member jika diminta
        if ($isMember !== null) {
            $query->where('is_member', (int) $isMember);
        }

        if ($request->filled('segment')) {
            $query->whereRaw(Customer::sqlSegmen().' = ?', [$request->input('segment')]);
        }

        if ($request->filled('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('tag', mb_strtolower(trim($request->input('tag')))));
        }

        // Search by name, phone, email, address, or project name
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('hobby', 'like', "%{$search}%")
                    ->orWhere('favorite_food', 'like', "%{$search}%")
                    ->orWhere('behavior', 'like', "%{$search}%")
                    ->orWhereHas('projects', function ($projectQuery) use ($search) {
                        $projectQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $request->validate($this->aturanSortir(self::SORTIR_PELANGGAN));
        $this->terapkanSortir($query, $request, self::SORTIR_PELANGGAN, 'customers.id');

        $customers = $query->orderBy('customers.created_at', 'desc')
            ->paginate($perPage);

        // Transform to include projects array
        $customers->getCollection()->transform(function ($customer) {
            $customer->project_names = $customer->projects->pluck('name')->toArray();
            $customer->project_ids = $customer->projects->pluck('id')->toArray();
            $this->rapikanRingkasan($customer);

            return $customer;
        });

        return response()->json($customers);
    }

    /**
     * Filter berdasarkan cabang:
     * - Customer yang terdaftar di cabang ini, ATAU
     * - Customer yang tidak punya cabang sama sekali (tampil di semua cabang)
     */
    private function pelangganCabang($query)
    {
        return $query->where(function ($q) {
            $q->whereHas('projects', function ($projectQuery) {
                $projectQuery->where('projects.id', 1);
            })->orWhereDoesntHave('projects'); // Tidak punya cabang = tampil di semua
        });
    }

    /** Angka dari agregat SQL datang sebagai teks; label segmen ikut disertakan. */
    private function rapikanRingkasan(Customer $customer): void
    {
        $customer->jumlah_pesanan = (int) $customer->jumlah_pesanan;
        $customer->total_belanja = (int) $customer->total_belanja;
        $customer->pesanan_pertama = $customer->pesanan_pertama !== null ? (int) $customer->pesanan_pertama : null;
        $customer->pesanan_terakhir = $customer->pesanan_terakhir !== null ? (int) $customer->pesanan_terakhir : null;
        $customer->segmen_label = Customer::SEGMEN[$customer->segmen] ?? null;
        if ($customer->relationLoaded('tags')) {
            $customer->setRelation('tags', $customer->tags->pluck('tag'));
        }
    }

    /**
     * GET /api/customers/segments — jumlah pelanggan per segmen, untuk chip
     * saringan di daftar pelanggan.
     */
    public function segments()
    {
        $jumlah = DB::query()
            ->fromSub($this->pelangganCabang(Customer::query()->denganRingkasanBelanja()), 'p')
            ->selectRaw('segmen, COUNT(*) AS jumlah')
            ->groupBy('segmen')
            ->pluck('jumlah', 'segmen');

        $data = [];
        foreach (Customer::SEGMEN as $kunci => $label) {
            $data[] = ['key' => $kunci, 'label' => $label, 'count' => (int) ($jumlah[$kunci] ?? 0)];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/customers/dashboard — ringkasan CRM untuk halaman Dashboard CRM.
     *
     * Semua angka turunan dari pesanan, memakai definisi segmen yang sama dengan
     * daftar pelanggan, jadi angka di dashboard dan jumlah di chip segmen tidak
     * pernah berselisih.
     */
    public function dashboard()
    {
        $pelanggan = fn () => $this->pelangganCabang(Customer::query()->denganRingkasanBelanja());
        $awalBulan = strtotime(date('Y-m-01'));

        $ringkas = DB::query()->fromSub($pelanggan(), 'p')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN jumlah_pesanan > 0 THEN 1 ELSE 0 END) AS pernah_order')
            ->selectRaw('SUM(CASE WHEN jumlah_pesanan >= 2 THEN 1 ELSE 0 END) AS repeat_order')
            ->selectRaw("SUM(CASE WHEN segmen IN ('vip', 'loyal', 'aktif', 'baru') THEN 1 ELSE 0 END) AS aktif")
            ->selectRaw('SUM(CASE WHEN pesanan_pertama >= ? THEN 1 ELSE 0 END) AS baru_bulan_ini', [$awalBulan])
            ->selectRaw('COALESCE(SUM(total_belanja), 0) AS omzet')
            ->first();

        $perSegmen = DB::query()->fromSub($pelanggan(), 'p')
            ->selectRaw('segmen, COUNT(*) AS jumlah, COALESCE(SUM(total_belanja), 0) AS belanja')
            ->groupBy('segmen')
            ->get()
            ->keyBy('segmen');

        $baris = fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'orders_count' => (int) $c->jumlah_pesanan,
            'total_spent' => (int) $c->total_belanja,
            'last_order_at' => $c->pesanan_terakhir !== null ? (int) $c->pesanan_terakhir : null,
            'segment' => $c->segmen,
        ];

        $teratas = $pelanggan()
            ->orderByDesc(DB::raw('COALESCE(rb.total_belanja, 0)'))
            ->limit(10)
            ->get()
            ->map($baris);

        $perluDisapa = $pelanggan()
            ->whereRaw(Customer::sqlSegmen().' = ?', ['mau_hilang'])
            ->orderByDesc(DB::raw('COALESCE(rb.total_belanja, 0)'))
            ->limit(10)
            ->get()
            ->map($baris);

        return response()->json(['data' => [
            'summary' => [
                'total' => (int) $ringkas->total,
                'ordered' => (int) $ringkas->pernah_order,
                'active' => (int) $ringkas->aktif,
                'new_this_month' => (int) $ringkas->baru_bulan_ini,
                'repeat_rate' => $ringkas->pernah_order > 0
                    ? round($ringkas->repeat_order / $ringkas->pernah_order * 100, 1)
                    : 0,
                'avg_spent' => $ringkas->pernah_order > 0
                    ? (int) round($ringkas->omzet / $ringkas->pernah_order)
                    : 0,
            ],
            'segments' => collect(Customer::SEGMEN)->map(fn ($label, $kunci) => [
                'key' => $kunci,
                'label' => $label,
                'count' => (int) ($perSegmen[$kunci]->jumlah ?? 0),
                'spent' => (int) ($perSegmen[$kunci]->belanja ?? 0),
            ])->values(),
            'monthly' => $this->pelangganPerBulan(),
            // Untuk teks penjelasan segmen di dashboard.
            'rules' => Customer::batasSegmen(),
            'top_customers' => $teratas,
            'at_risk' => $perluDisapa,
        ]]);
    }

    /**
     * Pelanggan yang order per bulan selama 12 bulan terakhir, dipisah menjadi
     * yang baru (order pertamanya di bulan itu) dan yang kembali.
     *
     * Dihitung di PHP, bukan dengan fungsi tanggal SQL, supaya sama di MariaDB
     * produksi dan SQLite pengujian. ponytail: menarik semua pesanan 12 bulan
     * dan tanggal order pertama tiap pelanggan; pindah ke GROUP BY bulan di SQL
     * kalau pesanan setahun sudah puluhan ribu.
     */
    private function pelangganPerBulan(): array
    {
        $awal = strtotime(date('Y-m-01', strtotime('-11 months', strtotime(date('Y-m-01')))));

        $orderPertama = DB::table('orders')
            ->where('is_deleted', 0)
            ->groupBy('customers_id')
            ->selectRaw('customers_id, MIN(date) AS pertama')
            ->pluck('pertama', 'customers_id');

        $bulan = [];
        for ($t = $awal; $t <= time(); $t = strtotime('+1 month', $t)) {
            $bulan[date('Y-m', $t)] = ['baru' => [], 'kembali' => []];
        }

        DB::table('orders')
            ->where('is_deleted', 0)
            ->where('date', '>=', $awal)
            ->orderBy('id')
            ->select(['customers_id', 'date'])
            ->each(function ($o) use (&$bulan, $orderPertama) {
                $kunci = date('Y-m', (int) $o->date);
                if (! isset($bulan[$kunci])) {
                    return;
                }
                $jenis = date('Y-m', (int) ($orderPertama[$o->customers_id] ?? $o->date)) === $kunci ? 'baru' : 'kembali';
                $bulan[$kunci][$jenis][$o->customers_id] = true;
            });

        return collect($bulan)->map(fn ($b, $kunci) => [
            'month' => $kunci,
            'new' => count($b['baru']),
            'returning' => count($b['kembali']),
        ])->values()->all();
    }

    /**
     * GET /api/customers/tags — tag yang sedang dipakai, beserta jumlah pelanggannya.
     */
    public function tags()
    {
        $data = CustomerTag::query()
            ->join('customers', 'customers.id', '=', 'customer_tags.customers_id')
            ->where('customers.is_deleted', 0)
            ->selectRaw('customer_tags.tag, COUNT(*) AS count')
            ->groupBy('customer_tags.tag')
            ->orderBy('customer_tags.tag')
            ->get()
            ->map(fn ($t) => ['tag' => $t->tag, 'count' => (int) $t->count]);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/customers/{id}/profile — satu halaman utuh tentang pelanggan.
     *
     * Pesanan dari SEMUA cabang ikut, masing-masing dengan nama cabangnya:
     * ringkasan belanja dan segmen dihitung dari seluruh cabang, dan riwayat yang
     * lebih pendek dari angka ringkasannya hanya membingungkan.
     */
    public function profile(string $id)
    {
        $customer = Customer::with(['projects', 'tags'])
            ->denganRingkasanBelanja()
            ->where('customers.id', $id)
            ->firstOrFail();

        $customer->project_names = $customer->projects->pluck('name')->toArray();
        $customer->project_ids = $customer->projects->pluck('id')->toArray();
        $this->rapikanRingkasan($customer);

        $tanpaCabang = fn ($q) => $q->withoutGlobalScope('branch');

        $pesanan = Order::withoutGlobalScope('branch')
            ->with(['project', 'items' => $tanpaCabang])
            ->withSum(['payments as dibayar' => $tanpaCabang], 'nominal')
            ->where('customers_id', $customer->id)
            ->orderByDesc('date')
            ->get();

        // Rumus piutang sama dengan dashboard: pesanan Proses/Siap Diambil yang
        // belum lunas.
        $piutang = $pesanan
            ->whereIn('status', [1, 2])
            ->sum(fn ($o) => max(0, (int) $o->total_price - (int) $o->dibayar));

        $idPesanan = $pesanan->pluck('id');
        $idBarang = $pesanan->flatMap(fn ($o) => $o->items->pluck('id'));
        $namaBarang = $pesanan->flatMap(fn ($o) => $o->items->map(fn ($i) => [$i->id, $i->name, $o->code]))
            ->keyBy(0);

        $pembayaran = Payment::withoutGlobalScope('branch')
            ->whereIn('orders_id', $idPesanan)
            ->orderByDesc('date')
            ->get(['id', 'orders_id', 'date', 'nominal', 'note']);
        $kodePesanan = $pesanan->pluck('code', 'id');

        $garansi = Guarantee::withoutGlobalScope('branch')
            ->whereIn('orders_items_id', $idBarang)
            ->orderByDesc('created_at')
            ->get();

        $penukaran = RewardRedemption::withoutGlobalScope('branch')
            ->with('reward')
            ->where('customers_id', $customer->id)
            ->orderByDesc('date')
            ->get();

        $catatan = $customer->notes()->orderByDesc('created_at')->get();
        $penulis = User::withoutGlobalScopes()
            ->whereIn('id', $catatan->pluck('created_by')->filter()->unique())
            ->pluck('name', 'id');

        // Nomor yang sama dipakai pelanggan lain: riwayat satu orang terpecah dua.
        $duplikat = $customer->phone
            ? Customer::where('phone', $customer->phone)->where('id', '!=', $customer->id)->get(['id', 'name'])
            : collect();

        return response()->json(['data' => [
            'customer' => $customer,
            'summary' => [
                'orders_count' => $customer->jumlah_pesanan,
                'total_spent' => $customer->total_belanja,
                'avg_order' => $customer->jumlah_pesanan > 0 ? (int) round($customer->total_belanja / $customer->jumlah_pesanan) : 0,
                'first_order_at' => $customer->pesanan_pertama,
                'last_order_at' => $customer->pesanan_terakhir,
                'receivable' => (int) $piutang,
                'points' => (int) $customer->points,
                'segment' => $customer->segmen,
                'segment_label' => $customer->segmen_label,
            ],
            'orders' => $pesanan->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'date' => $o->date,
                'status' => $o->status,
                'total_price' => (int) $o->total_price,
                'paid' => (int) $o->dibayar,
                'remaining' => max(0, $o->sisaTagihan((int) $o->dibayar)),
                'branch_name' => $o->project?->name,
                'items' => $o->items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'status' => $i->status])->values(),
            ])->values(),
            'payments' => $pembayaran->map(fn ($p) => [
                'id' => $p->id,
                'order_code' => $kodePesanan[$p->orders_id] ?? null,
                'date' => $p->date,
                'nominal' => (int) $p->nominal,
                'note' => $p->note,
            ])->values(),
            'guarantees' => $garansi->map(fn ($g) => [
                'id' => $g->id,
                'item_name' => $namaBarang[$g->orders_items_id][1] ?? null,
                'order_code' => $namaBarang[$g->orders_items_id][2] ?? null,
                'note' => $g->note,
                'status' => $g->status,
                'status_label' => GuaranteeClaimController::STATUS_LABELS[$g->status] ?? 'Tidak diketahui',
                'created_at' => $g->created_at,
            ])->values(),
            'redemptions' => $penukaran->map(fn ($r) => [
                'id' => $r->id,
                'reward_name' => $r->reward?->name,
                'points_spent' => (int) $r->points_spent,
                'status_label' => $r->status === 1 ? 'Sudah diambil' : 'Menunggu diambil',
                'date' => $r->date,
            ])->values(),
            'notes' => $catatan->map(fn ($n) => [
                'id' => $n->id,
                'note' => $n->note,
                'author' => $penulis[$n->created_by] ?? null,
                'created_at' => $n->created_at,
            ])->values(),
            'duplicates' => $duplikat,
        ]]);
    }

    /** POST /api/customers/{id}/notes */
    public function storeNote(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $note = $customer->notes()->create([
            'note' => trim($validated['note']),
            'created_by' => auth()->id(),
        ]);

        return response()->json(['data' => [
            'id' => $note->id,
            'note' => $note->note,
            'author' => auth()->user()?->name,
            'created_at' => $note->created_at,
        ]], 201);
    }

    /** DELETE /api/customers/{id}/notes/{noteId} */
    public function destroyNote(string $id, string $noteId)
    {
        $note = CustomerNote::where('customers_id', $id)->findOrFail($noteId);
        $note->update(['is_deleted' => 1, 'modified_by' => auth()->id()]);

        return response()->json(['message' => 'Catatan dihapus']);
    }

    /**
     * PUT /api/customers/{id}/tags — ganti seluruh tag pelanggan.
     *
     * Tag disimpan huruf kecil supaya "Reseller" dan "reseller" tidak menjadi
     * dua saringan berbeda.
     */
    public function syncTags(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);
        $validated = $request->validate([
            'tags' => ['present', 'array', 'max:20'],
            'tags.*' => ['nullable', 'string', 'max:50'],
        ]);

        $tags = collect($validated['tags'])
            ->map(fn ($t) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $t))))
            ->filter()
            ->unique()
            ->values();

        DB::transaction(function () use ($customer, $tags) {
            $customer->tags()->whereNotIn('tag', $tags)->delete();
            $ada = $customer->tags()->pluck('tag');
            foreach ($tags->diff($ada) as $tag) {
                $customer->tags()->create(['tag' => $tag, 'created_by' => auth()->id()]);
            }
        });

        return response()->json(['data' => $tags]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string',
            'email' => 'nullable|email|max:200',
            'instagram' => 'nullable|string|max:100',
            'photo' => 'nullable|string',
            'maps' => 'nullable|string',
            'date_of_birth' => 'nullable|integer',
            'hobby' => 'nullable|string',
            'favorite_food' => 'nullable|string',
            'behavior' => 'nullable|string',
            'is_member' => 'nullable|boolean',
            'member_code' => 'nullable|string|max:50|unique:customers,member_code',
            'member_since' => 'nullable|date',
            'points' => 'nullable|integer|min:0',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'exists:projects,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $projectIds = $data['project_ids'] ?? [];
        unset($data['project_ids']);

        // Normalize phone number
        if (isset($data['phone'])) {
            $data['phone'] = $this->normalizePhone($data['phone']);
        }

        $data['projects_id'] = 1;
        $data['created_by'] = auth()->id();

        $customer = Customer::create($data);

        // Sync projects (many-to-many)
        if (! empty($projectIds)) {
            $customer->projects()->sync($projectIds);
        }

        $customer->load('projects');
        $customer->project_names = $customer->projects->pluck('name')->toArray();
        $customer->project_ids = $customer->projects->pluck('id')->toArray();

        return response()->json(['data' => $customer]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $customer = Customer::with('projects')->where('projects_id', 1)->findOrFail($id);

        $customer->project_names = $customer->projects->pluck('name')->toArray();
        $customer->project_ids = $customer->projects->pluck('id')->toArray();

        return response()->json(['data' => $customer]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $customer = Customer::where('projects_id', 1)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'phone' => 'required|string|max:20',
            'address' => 'nullable|string',
            'email' => 'nullable|email|max:200',
            'instagram' => 'nullable|string|max:100',
            'photo' => 'nullable|string',
            'maps' => 'nullable|string',
            'date_of_birth' => 'nullable|integer',
            'hobby' => 'nullable|string',
            'favorite_food' => 'nullable|string',
            'behavior' => 'nullable|string',
            'is_member' => 'nullable|boolean',
            'member_code' => 'nullable|string|max:50|unique:customers,member_code,'.$id,
            'member_since' => 'nullable|date',
            'points' => 'nullable|integer|min:0',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'exists:projects,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $projectIds = $data['project_ids'] ?? [];
        unset($data['project_ids']);

        // Normalize phone number
        if (isset($data['phone'])) {
            $data['phone'] = $this->normalizePhone($data['phone']);
        }

        $data['modified_by'] = auth()->id();

        $customer->update($data);

        // Sync projects (many-to-many)
        if (! empty($projectIds)) {
            $customer->projects()->sync($projectIds);
        } else {
            $customer->projects()->detach();
        }

        $customer->load('projects');
        $customer->project_names = $customer->projects->pluck('name')->toArray();
        $customer->project_ids = $customer->projects->pluck('id')->toArray();

        return response()->json(['data' => $customer]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $customer = Customer::where('projects_id', 1)->findOrFail($id);

        $customer->update([
            'is_deleted' => 1,
            'modified_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    /**
     * POST /api/customers/{id}/reset-pin
     *
     * Jalur pemulihan untuk 3.781 pelanggan yang tidak punya email, dan jalan
     * keluar kalau akun diklaim orang lain saat PIN pertama dibuat.
     */
    public function resetPin(string $id)
    {
        $customer = Customer::find($id);

        if (! $customer) {
            return response()->json(['message' => 'Pelanggan tidak ditemukan'], 404);
        }

        $customer->update([
            'pin' => null,
            'pin_created_at' => null,
            'pin_created_ip' => null,
            'modified_by' => auth()->id(),
        ]);

        // Mencabut token wajib: kalau sesi pengklaim tetap hidup, mengosongkan
        // PIN tidak mengembalikan akun ke pemilik aslinya.
        $customer->tokens()->delete();

        return response()->json([
            'message' => 'PIN direset. Pelanggan bisa membuat PIN baru saat masuk.',
        ]);
    }

    /**
     * Normalize phone number (remove 0 or 62 prefix)
     */
    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $normalized = preg_replace('/\D/', '', $phone);

        if (str_starts_with($normalized, '62')) {
            $normalized = substr($normalized, 2);
        } elseif (str_starts_with($normalized, '0')) {
            $normalized = substr($normalized, 1);
        }

        return $normalized ?: null;
    }
}
