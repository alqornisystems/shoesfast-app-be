<?php

namespace Tests\Feature\Customer;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gerbang captcha di jalur masuk portal pelanggan.
 *
 * Dua sifatnya yang paling menentukan, dan keduanya dikunci di sini:
 *
 * 1. Kosong = mati. Portal sudah hidup dan dipakai; rilis yang menolak semua permintaan
 *    sebelum kunci disetel akan mengunci SELURUH pelanggan di luar tanpa satu pun galat
 *    yang menjelaskan kenapa.
 * 2. Penyedia tidak bisa dihubungi = dilewatkan. Memblokir pelanggan karena layanan
 *    pihak ketiga sedang mati adalah kerugian yang lebih besar daripada bot yang lolos.
 */
class CaptchaGateTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
    }

    public function test_tanpa_kunci_gerbangnya_mati_dan_portal_tetap_jalan(): void
    {
        config(['services.captcha.secret' => '']);

        $this->postJson('/api/customer/auth/check-phone', ['phone' => '81200001111'])
            ->assertStatus(200);
    }

    public function test_dengan_kunci_permintaan_tanpa_token_ditolak(): void
    {
        config(['services.captcha.secret' => 'rahasia-uji']);

        $this->postJson('/api/customer/auth/check-phone', ['phone' => '81200001111'])
            ->assertStatus(422);
    }

    public function test_token_yang_ditolak_penyedia_juga_ditolak(): void
    {
        config(['services.captcha.secret' => 'rahasia-uji']);
        Http::fake(['*' => Http::response(['success' => false], 200)]);

        $this->postJson('/api/customer/auth/check-phone', [
            'phone' => '81200001111',
            'captcha_token' => 'token-palsu',
        ])->assertStatus(422);
    }

    public function test_token_sah_diteruskan(): void
    {
        config(['services.captcha.secret' => 'rahasia-uji']);
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->postJson('/api/customer/auth/check-phone', [
            'phone' => '81200001111',
            'captcha_token' => 'token-sah',
        ])->assertStatus(200);
    }

    /** reCAPTCHA v3 menjawab dengan skor; Turnstile tidak. Ambang hanya berlaku bila ada. */
    public function test_skor_di_bawah_ambang_ditolak(): void
    {
        config([
            'services.captcha.secret' => 'rahasia-uji',
            'services.captcha.min_score' => 0.5,
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'score' => 0.1], 200)]);

        $this->postJson('/api/customer/auth/check-phone', [
            'phone' => '81200001111',
            'captcha_token' => 'token-sah',
        ])->assertStatus(422);
    }

    public function test_penyedia_mati_tidak_mengunci_pelanggan(): void
    {
        config(['services.captcha.secret' => 'rahasia-uji']);
        Http::fake(fn () => throw new \RuntimeException('jaringan mati'));

        $this->postJson('/api/customer/auth/check-phone', [
            'phone' => '81200001111',
            'captcha_token' => 'token-apa-pun',
        ])->assertStatus(200);
    }

    /** Token boleh lewat header, supaya klien tidak perlu menyisipkannya ke tiap badan. */
    public function test_token_lewat_header_juga_diterima(): void
    {
        config(['services.captcha.secret' => 'rahasia-uji']);
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $this->postJson(
            '/api/customer/auth/check-phone',
            ['phone' => '81200001111'],
            ['X-Captcha-Token' => 'token-sah']
        )->assertStatus(200);
    }
}
