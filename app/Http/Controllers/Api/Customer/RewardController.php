<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Reward;
use App\Models\RewardRedemption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RewardController extends Controller
{
    // GET /api/customer/rewards
    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $rewards = Reward::withoutGlobalScope('branch')
            ->where('projects_id', $customer->projects_id)
            ->where('is_active', 1)
            ->orderBy('points_cost')
            ->get()
            ->map(fn (Reward $reward) => [
                'id' => $reward->id,
                'name' => $reward->name,
                'type' => $reward->type,
                'points_cost' => $reward->points_cost,
                'photo' => $reward->photo,
                'affordable' => (int) $customer->points >= $reward->points_cost,
            ]);

        return response()->json(['data' => $rewards]);
    }

    // POST /api/customer/rewards/{id}/redeem
    public function redeem(Request $request, int $id): JsonResponse
    {
        $customer = $request->user();

        $reward = Reward::withoutGlobalScope('branch')
            ->where('id', $id)
            ->where('projects_id', $customer->projects_id)
            ->where('is_active', 1)
            ->first();

        if (! $reward) {
            return response()->json(['message' => 'Hadiah tidak ditemukan'], 404);
        }

        try {
            $result = DB::transaction(function () use ($customer, $reward) {
                // Kunci baris pelanggan: dua permintaan bersamaan dari dua
                // perangkat tidak boleh menghabiskan saldo yang sama dua kali.
                $locked = Customer::withoutGlobalScope('branch')
                    ->where('id', $customer->id)
                    ->lockForUpdate()
                    ->first();

                if ((int) $locked->points < $reward->points_cost) {
                    throw new \RuntimeException('insufficient');
                }

                $locked->update(['points' => (int) $locked->points - $reward->points_cost]);

                // Poin dipotong saat menukar, bukan saat barang diambil,
                // supaya hadiah yang sama tidak bisa ditukar dua kali.
                $redemption = RewardRedemption::withoutGlobalScope('branch')->create([
                    'projects_id' => $customer->projects_id,
                    'customers_id' => $locked->id,
                    'rewards_id' => $reward->id,
                    'code' => 'TKR'.strtoupper(Str::random(8)),
                    'points_spent' => $reward->points_cost,
                    'status' => 0,
                    'date' => time(),
                ]);

                return [
                    'code' => $redemption->code,
                    'points_spent' => $redemption->points_spent,
                    'points_left' => (int) $locked->points,
                ];
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Poin kamu belum cukup untuk hadiah ini.'], 422);
        }

        return response()->json($result, 201);
    }

    // GET /api/customer/redemptions
    public function history(Request $request): JsonResponse
    {
        $customer = $request->user();

        $rows = RewardRedemption::withoutGlobalScope('branch')
            ->with('reward')
            ->where('customers_id', $customer->id)
            ->where('projects_id', $customer->projects_id)
            ->orderByDesc('date')
            ->get()
            ->map(fn (RewardRedemption $redemption) => [
                'code' => $redemption->code,
                'reward_name' => $redemption->reward?->name,
                'points_spent' => $redemption->points_spent,
                'status' => $redemption->status,
                'status_label' => $redemption->status === 1 ? 'Sudah diambil' : 'Menunggu diambil',
                'date' => $redemption->date,
            ]);

        return response()->json(['data' => $rows]);
    }
}
