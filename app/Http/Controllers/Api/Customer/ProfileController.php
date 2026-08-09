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
            'instagram' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'integer'],
            // Kolom `photo` bertipe TEXT (batas 65.535 byte). Tanpa batas ini
            // foto yang lebih besar dipotong diam-diam oleh MySQL dan yang
            // tersimpan adalah data URL rusak yang tidak bisa dirender lagi.
            // Portal sudah mengecilkan gambar sebelum mengirim; batas ini
            // yang menjaga kalau ada yang menembak endpoint langsung.
            'photo' => ['nullable', 'string', 'max:60000'],
            'address' => ['nullable', 'string'],
            'maps' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $customer->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? $customer->email,
            'instagram' => $validated['instagram'] ?? $customer->instagram,
            'date_of_birth' => $validated['date_of_birth'] ?? $customer->date_of_birth,
            // Dibaca dengan array_key_exists, bukan ??: mengirim photo:null
            // adalah cara portal menghapus foto, dan ?? akan membacanya
            // sebagai "tidak diubah" sehingga tombol hapus tidak pernah
            // benar-benar menghapus.
            'photo' => array_key_exists('photo', $validated)
                ? $validated['photo']
                : $customer->photo,
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
