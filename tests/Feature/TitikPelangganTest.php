<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\Feature\Customer\CreatesCustomerSchema;
use Tests\TestCase;

/**
 * Aturan satu arah: `maps` yang dipegang manusia, koordinat turunannya.
 *
 * Diuji di sini karena aturannya tinggal di hook `saving` model — bukan di
 * controller — supaya kelima jalur tulis yang ada hari ini (admin store, admin
 * update, endpoint titik, portal profil, portal register) ikut benar tanpa
 * harus diingat satu per satu.
 */
class TitikPelangganTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
    }

    private function buatPelanggan(array $atribut = []): Customer
    {
        return Customer::create(array_merge([
            'projects_id' => 1,
            'name' => 'Pelanggan Uji',
            'phone' => '81234567890',
            'is_deleted' => 0,
        ], $atribut));
    }

    public function test_menyimpan_maps_mengisi_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/place/Citraland/@-7.28848,112.6331921,17z',
        ]);

        $this->assertSame(-7.28848, (float) $pelanggan->latitude);
        $this->assertSame(112.6331921, (float) $pelanggan->longitude);
    }

    public function test_mengganti_maps_menurunkan_koordinat_baru(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['maps' => 'https://www.google.com/maps/@-8.2,113.2,17z']);

        $this->assertSame(-8.2, (float) $pelanggan->fresh()->latitude);
        $this->assertSame(113.2, (float) $pelanggan->fresh()->longitude);
    }

    public function test_mengosongkan_maps_mengosongkan_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['maps' => null]);

        $this->assertNull($pelanggan->fresh()->latitude);
        $this->assertNull($pelanggan->fresh()->longitude);
    }

    public function test_maps_tanpa_koordinat_mengosongkan_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['maps' => 'https://www.google.com/maps/search/Jalan+Mojo']);

        $this->assertNull($pelanggan->fresh()->latitude);
    }

    public function test_koordinat_tanpa_maps_dipakai_apa_adanya(): void
    {
        // Jalur portal pelanggan: Customer/ProfileController menerima
        // latitude/longitude langsung. Hook tidak boleh membuangnya.
        $pelanggan = $this->buatPelanggan([
            'latitude' => -7.5,
            'longitude' => 112.5,
        ]);

        $this->assertSame(-7.5, (float) $pelanggan->latitude);
        $this->assertSame(112.5, (float) $pelanggan->longitude);
    }

    public function test_maps_menang_atas_koordinat_yang_dikirim_bersamaan(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
            'latitude' => -1.1,
            'longitude' => 101.1,
        ]);

        $this->assertSame(-7.28848, (float) $pelanggan->latitude);
        $this->assertSame(112.6331921, (float) $pelanggan->longitude);
    }

    /**
     * Review Focus 5: ketiga aturan di spec bertabrakan kalau `maps`
     * dikosongkan sementara koordinat dikirim eksplisit dalam satu simpanan.
     * Resolusinya: URL yang bisa diparse selalu menang; kalau URL kosong atau
     * tidak memuat titik, angka eksplisit dihormati.
     */
    public function test_maps_dikosongkan_bersama_koordinat_eksplisit_menghormati_angkanya(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update([
            'maps' => null,
            'latitude' => -9.9,
            'longitude' => 119.9,
        ]);

        $this->assertSame(-9.9, (float) $pelanggan->fresh()->latitude);
        $this->assertSame(119.9, (float) $pelanggan->fresh()->longitude);
    }

    public function test_menyimpan_kolom_lain_tidak_menyentuh_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['name' => 'Nama Baru']);

        $this->assertSame(-7.1, (float) $pelanggan->fresh()->latitude);
    }
}
