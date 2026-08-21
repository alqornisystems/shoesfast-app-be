<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifikasi captcha untuk jalur masuk portal pelanggan.
 *
 * Yang ditahan ini adalah BOT: pendaftaran borongan, penyapuan nomor mana yang terdaftar,
 * dan gempuran tebak-PIN dari banyak IP. Ia TIDAK menahan manusia yang mengetik nomor
 * orang lain — untuk itu yang diperlukan verifikasi kepemilikan nomor (OTP), dan sepanjang
 * itu belum ada, klaim akun lama tetap "siapa cepat dia dapat" dengan `pin_created_ip`
 * sebagai satu-satunya jejak.
 *
 * FAIL OPEN kalau rahasianya belum diisi. Ini disengaja dan penting: portal sudah hidup
 * dan dipakai. Kalau middleware ini menolak semua permintaan sebelum kunci disetel, rilis
 * berikutnya mengunci SELURUH pelanggan di luar tanpa satu pun galat yang menjelaskan
 * kenapa. Perlindungan yang mati lebih baik daripada toko yang mati.
 */
class VerifyCaptcha
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.captcha.secret');

        if ($secret === '') {
            return $next($request);
        }

        $token = $request->header('X-Captcha-Token') ?: $request->input('captcha_token');

        if (! $token) {
            return response()->json([
                'message' => 'Verifikasi keamanan belum selesai. Muat ulang halaman lalu coba lagi.',
            ], 422);
        }

        if (! $this->sah((string) $token, $secret, $request->ip())) {
            return response()->json([
                'message' => 'Verifikasi keamanan gagal. Muat ulang halaman lalu coba lagi.',
            ], 422);
        }

        return $next($request);
    }

    private function sah(string $token, string $secret, ?string $ip): bool
    {
        try {
            $balasan = Http::asForm()
                ->timeout(8)
                ->post((string) config('services.captcha.verify_url'), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);
        } catch (\Throwable $e) {
            // Penyedia captcha tidak bisa dihubungi. Dilewatkan, dengan alasan yang sama
            // seperti fail open di atas: memblokir pelanggan karena layanan pihak ketiga
            // sedang mati adalah kerugian yang lebih besar daripada bot yang lolos.
            Log::warning('Verifikasi captcha tidak bisa dihubungi', ['error' => $e->getMessage()]);

            return true;
        }

        $isi = $balasan->json();

        if (! ($isi['success'] ?? false)) {
            return false;
        }

        // reCAPTCHA v3 mengembalikan skor 0..1; Turnstile tidak. Ambang hanya diberlakukan
        // kalau penyedianya memang mengirim skor.
        $minimal = (float) config('services.captcha.min_score');

        if ($minimal > 0 && isset($isi['score'])) {
            return (float) $isi['score'] >= $minimal;
        }

        return true;
    }
}
