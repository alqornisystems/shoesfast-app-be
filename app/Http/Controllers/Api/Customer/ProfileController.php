<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly AuthController $auth) {}

    // PUT /api/customer/profile
    public function update(Request $request): JsonResponse
    {
        $customer = $request->user();

        // projects_id, points, is_member, dan pin sengaja TIDAK ada di daftar
        // ini. Pelanggan tidak boleh memindahkan dirinya antar cabang atau
        // menambah poinnya sendiri lewat badan permintaan.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:225'],
            'photo' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'maps' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $customer->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? $customer->email,
            'photo' => $validated['photo'] ?? $customer->photo,
            'address' => $validated['address'] ?? $customer->address,
            'maps' => $validated['maps'] ?? $customer->maps,
            'latitude' => $validated['latitude'] ?? $customer->latitude,
            'longitude' => $validated['longitude'] ?? $customer->longitude,
            'modified_by' => $customer->id,
        ]);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'customer' => $this->auth->presentPublic($customer->fresh()),
        ]);
    }
}
