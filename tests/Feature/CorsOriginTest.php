<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Daftar origin yang diizinkan CORS.
 *
 * Kegagalan CORS tidak meninggalkan jejak apa pun di log server — yang terlihat hanya
 * halaman kosong di HP pelanggan. Karena itu domain produksi sendiri dikunci di test,
 * bukan diserahkan sepenuhnya ke .env server yang dikelola manual dan tidak masuk git.
 */
class CorsOriginTest extends TestCase
{
    public function test_domain_produksi_sendiri_selalu_diizinkan(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertContains('https://customer.shoesfast.id', $origins);
        $this->assertContains('https://app.shoesfast.id', $origins);
    }

    /**
     * config/app.php mengambil entri PERTAMA FRONTEND_URL sebagai frontend_url untuk
     * tautan invoice. Kalau bawaan menyelinap ke depan, tautan invoice yang dikirim ke
     * pelanggan lewat WhatsApp akan menunjuk aplikasi yang salah.
     */
    public function test_origin_dari_env_tetap_di_urutan_pertama(): void
    {
        $origins = config('cors.allowed_origins');
        $env = array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', '')))));

        if ($env === []) {
            $this->markTestSkipped('FRONTEND_URL kosong di lingkungan test.');
        }

        $this->assertSame($env[0], $origins[0]);
    }

    public function test_tidak_pernah_mengizinkan_semua_origin(): void
    {
        // supports_credentials aktif, dan "*" bersama credentials adalah kombinasi yang
        // ditolak spesifikasi CORS — browser akan memblokirnya diam-diam.
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }
}
