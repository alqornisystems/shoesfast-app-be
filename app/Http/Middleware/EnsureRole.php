<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureRole
 *
 * Membatasi route ke daftar nama role tertentu:
 *
 *     ->middleware('role:Admin Super,Admin,Finance')
 *
 * Dipakai SETELAH `auth:sanctum` — middleware ini tidak mengurus autentikasi, hanya otorisasi.
 *
 * Catatan penting soal "Admin Super": role itu TIDAK otomatis lolos. Setiap daftar menyebutkan
 * 'Admin Super' secara eksplisit. Alasannya, bypass diam-diam adalah cara paling gampang membuat
 * satu route kehilangan pagarnya tanpa ada yang sadar — kalau nanti ada role baru yang perlu
 * akses penuh, tambahkan namanya ke daftar, jangan bikin pintu belakang.
 *
 * Perbandingan nama role case-insensitive supaya selisih huruf besar/kecil di tabel `roles`
 * (data lama, diisi manual) tidak diam-diam mengunci orang yang berhak.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Tidak terautentikasi.'], 401);
        }

        $userRole = $user->role?->name;

        if ($userRole === null) {
            return response()->json([
                'message' => 'Akun Anda belum memiliki jabatan. Hubungi admin.',
            ], 403);
        }

        foreach ($roles as $allowed) {
            if (strcasecmp(trim($allowed), trim($userRole)) === 0) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Jabatan Anda tidak memiliki akses ke menu ini.',
        ], 403);
    }
}
