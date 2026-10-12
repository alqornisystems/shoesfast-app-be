<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Customer\CreatesCustomerSchema;
use Tests\TestCase;

/**
 * Backfill memanen koordinat dari URL yang sudah tersimpan.
 *
 * Yang diuji bukan jumlah barisnya di produksi (3.913), tapi dua sifat yang
 * membuatnya aman dijalankan di server: hanya menyentuh baris yang koordinatnya
 * masih kosong, dan hasilnya sama kalau dijalankan dua kali.
 *
 * Migrasinya dipanggil LANGSUNG, bukan disalin ke dalam test. Test yang
 * menjalankan tiruan logikanya akan tetap hijau sesudah migrasi aslinya
 * berubah — dan itu test yang berbohong.
 */
class BackfillTitikPelangganTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
    }

    private function jalankanBackfill(): void
    {
        $migrasi = require database_path('migrations/2026_10_12_000001_backfill_customer_point.php');
        $migrasi->up();
    }

    private function sisipkan(array $atribut): int
    {
        return DB::table('customers')->insertGetId(array_merge([
            'projects_id' => 1,
            'name' => 'Pelanggan',
            'phone' => '81200000000',
            'is_deleted' => 0,
        ], $atribut));
    }

    public function test_mengisi_baris_yang_koordinatnya_kosong(): void
    {
        $id = $this->sisipkan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
        ]);

        $this->jalankanBackfill();

        $baris = DB::table('customers')->find($id);
        $this->assertSame(-7.28848, (float) $baris->latitude);
        $this->assertSame(112.6331921, (float) $baris->longitude);
    }

    public function test_tidak_menimpa_titik_yang_sudah_ada(): void
    {
        $id = $this->sisipkan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
            'latitude' => -1.5,
            'longitude' => 101.5,
        ]);

        $this->jalankanBackfill();

        $this->assertSame(-1.5, (float) DB::table('customers')->find($id)->latitude);
    }

    public function test_melewati_url_tanpa_koordinat_tanpa_menggagalkan_sisanya(): void
    {
        $rusak = $this->sisipkan(['maps' => 'depan lapangan, pagar hijau']);
        $benar = $this->sisipkan(['maps' => 'https://www.google.com/maps/@-7.1,112.1,17z']);

        $this->jalankanBackfill();

        $this->assertNull(DB::table('customers')->find($rusak)->latitude);
        $this->assertSame(-7.1, (float) DB::table('customers')->find($benar)->latitude);
    }

    /** Review Focus 4: baris terhapus lunak ikut dipanen, tapi tidak dihitung gagal. */
    public function test_baris_terhapus_lunak_ikut_dipanen(): void
    {
        $id = $this->sisipkan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
            'is_deleted' => 1,
        ]);

        $this->jalankanBackfill();

        $this->assertSame(-7.1, (float) DB::table('customers')->find($id)->latitude);
    }

    public function test_tidak_menyentuh_modified_at_karena_bukan_suntingan_manusia(): void
    {
        $id = $this->sisipkan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
            'modified_at' => 1000,
            'modified_by' => 7,
        ]);

        $this->jalankanBackfill();

        $baris = DB::table('customers')->find($id);
        $this->assertSame(1000, (int) $baris->modified_at);
        $this->assertSame(7, (int) $baris->modified_by);
    }

    public function test_dijalankan_dua_kali_tidak_mengubah_apa_pun(): void
    {
        $id = $this->sisipkan(['maps' => 'https://www.google.com/maps/@-7.1,112.1,17z']);

        $this->jalankanBackfill();
        $pertama = DB::table('customers')->find($id);

        $this->jalankanBackfill();
        $kedua = DB::table('customers')->find($id);

        $this->assertSame((float) $pertama->latitude, (float) $kedua->latitude);
        $this->assertSame((float) $pertama->longitude, (float) $kedua->longitude);
    }
}
