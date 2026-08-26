<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\RewardRedemption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedemptionController extends Controller
{
    use \App\Http\Controllers\Concerns\DaftarTersortir;

    /** Kolom yang boleh dipakai mengurutkan daftar ini. */
    private const SORTIR_PENUKARAN = [
        'date' => 'reward_redemptions.date',
        'points_spent' => 'reward_redemptions.points_spent',
        'status' => 'reward_redemptions.status',
        'code' => 'reward_redemptions.code',
    ];

    // GET /api/redemptions
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 25);
        $search = trim((string) $request->get('search', ''));
        $status = $request->get('status');

        $query = RewardRedemption::with('reward')->orderByDesc('date');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhereIn(
                        'customers_id',
                        Customer::where('name', 'like', "%{$search}%")->pluck('id')
                    );
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $request->validate($this->aturanSortir(self::SORTIR_PENUKARAN));
        $this->terapkanSortir($query, $request, self::SORTIR_PENUKARAN, 'reward_redemptions.id');

        $paginator = $query->paginate($perPage);

        // Nama pelanggan diambil sekali untuk seluruh halaman, bukan per baris.
        $names = Customer::whereIn('id', $paginator->getCollection()->pluck('customers_id'))
            ->pluck('name', 'id');

        $paginator->getCollection()->transform(fn (RewardRedemption $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'customer_name' => $names[$r->customers_id] ?? null,
            'reward_name' => $r->reward?->name,
            'points_spent' => $r->points_spent,
            'status' => $r->status,
            'status_label' => $r->status === 1 ? 'Sudah diambil' : 'Menunggu diambil',
            'date' => $r->date,
        ]);

        return response()->json($paginator);
    }

    // POST /api/redemptions/{id}/complete
    public function complete(int $id): JsonResponse
    {
        $redemption = RewardRedemption::find($id);

        if (! $redemption) {
            return response()->json(['message' => 'Penukaran tidak ditemukan'], 404);
        }

        // Poin sudah dipotong saat pelanggan menukar. Menandai selesai hanya
        // mencatat serah terima; tidak ada saldo yang disentuh di sini, jadi
        // menekannya dua kali tidak merugikan siapa pun.
        $redemption->update([
            'status' => 1,
            'modified_by' => auth()->id(),
        ]);

        return response()->json(['message' => 'Penukaran ditandai sudah diambil']);
    }
}
