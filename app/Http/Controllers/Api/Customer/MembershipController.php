<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\CustomerPointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController extends Controller
{
    public function __construct(private readonly AuthController $auth) {}

    // POST /api/customer/membership/join
    public function join(Request $request): JsonResponse
    {
        $customer = $request->user();

        // Bergabung dua kali tidak menerbitkan kode baru: kode lama sudah
        // dipegang pelanggan dan mungkin sudah dicatat admin.
        if ((int) $customer->is_member !== 1) {
            $customer->update([
                'is_member' => 1,
                'member_code' => 'MBR'.str_pad((string) $customer->id, 6, '0', STR_PAD_LEFT),
                'member_since' => time(),
            ]);
        }

        return response()->json([
            'message' => 'Selamat, kamu sekarang member Shoesfast.',
            'customer' => $this->auth->presentPublic($customer->fresh()),
        ]);
    }

    // GET /api/customer/membership
    public function show(Request $request, CustomerPointService $points): JsonResponse
    {
        $customer = $request->user();

        return response()->json([
            'is_member' => (int) $customer->is_member,
            'member_code' => $customer->member_code,
            'member_since' => $customer->member_since,
            'points' => (int) $customer->points,
            'rupiah_per_point' => $points->rupiahPerPoint(),
        ]);
    }
}
