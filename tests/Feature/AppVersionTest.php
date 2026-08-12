<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Gerbang versi minimum aplikasi mobile (GET /api/app/version).
 *
 * Yang paling penting dijaga di sini adalah perilaku saat settings KOSONG. Endpoint ini
 * memutuskan apakah seluruh pengguna terkunci di layar "perbarui dulu", jadi kegagalan
 * membaca setting harus berarti gerbangnya mati — bukan aplikasinya mati.
 */
class AppVersionTest extends TestCase
{
    public function test_terbuka_tanpa_login(): void
    {
        $this->getJson('/api/app/version')->assertStatus(200);
    }

    public function test_tanpa_settings_gerbangnya_mati_bukan_mengunci_semua_orang(): void
    {
        // Tabel settings sengaja tidak dibuat: Setting::read() harus degradasi ke bawaan.
        $this->getJson('/api/app/version')
            ->assertStatus(200)
            ->assertJsonPath('min_version', '0.0.0')
            ->assertJsonPath('store_url', null);
    }

    public function test_hanya_tiga_kunci_yang_dibocorkan(): void
    {
        $isi = $this->getJson('/api/app/version')->assertStatus(200)->json();

        // Tabel settings juga menyimpan kredensial WAHA. Endpoint publik ini harus memakai
        // daftar putih, bukan mengembalikan tabel lalu membuang yang rahasia.
        $this->assertSame(
            ['min_version', 'latest_version', 'store_url'],
            array_keys($isi)
        );
    }
}
