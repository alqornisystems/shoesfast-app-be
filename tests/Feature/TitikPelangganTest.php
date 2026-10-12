<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
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

    private function masukSebagaiAdmin(): void
    {
        $user = new User(['name' => 'Admin Uji', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => 'Admin']));

        Sanctum::actingAs($user);
    }

    public function test_endpoint_titik_menyimpan_maps_dan_membalas_koordinatnya(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan();

        $this->putJson("/api/customers/{$pelanggan->id}/point", [
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
        ])
            ->assertOk()
            ->assertJsonPath('data.latitude', -7.28848)
            ->assertJsonPath('data.longitude', 112.6331921);

        $this->assertSame(-7.28848, (float) $pelanggan->fresh()->latitude);
    }

    public function test_endpoint_titik_tidak_menyentuh_kolom_lain(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan([
            'name' => 'Nama Asli',
            'phone' => '81200000000',
            'address' => 'Alamat Asli',
        ]);

        $this->putJson("/api/customers/{$pelanggan->id}/point", [
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ])->assertOk();

        $segar = $pelanggan->fresh();
        $this->assertSame('Nama Asli', $segar->name);
        $this->assertSame('81200000000', $segar->phone);
        $this->assertSame('Alamat Asli', $segar->address);
    }

    /** Review Focus 3: admin yang sadar titiknya salah butuh jalan membatalkannya. */
    public function test_endpoint_titik_menerima_null_untuk_menghapus_titik(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $this->putJson("/api/customers/{$pelanggan->id}/point", ['maps' => null])
            ->assertOk()
            ->assertJsonPath('data.latitude', null);

        $this->assertNull($pelanggan->fresh()->maps);
    }

    public function test_endpoint_titik_menolak_maps_bukan_string(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan();

        $this->putJson("/api/customers/{$pelanggan->id}/point", ['maps' => ['a' => 1]])
            ->assertStatus(422);
    }

    /**
     * Review Focus 4: pelanggan yang sudah dihapus lunak tidak bisa disunting.
     *
     * Pelanggan yang hidup diuji lebih dulu DI DALAM test yang sama, dengan
     * sengaja: rute yang belum ada juga menjawab 404, jadi tanpa langkah
     * pertama ini test ini akan hijau bahkan sebelum endpointnya dibuat — dan
     * test yang tidak pernah bisa dilihat gagal tidak membuktikan apa pun.
     */
    public function test_endpoint_titik_404_untuk_pelanggan_terhapus(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan();

        $this->putJson("/api/customers/{$pelanggan->id}/point", [
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ])->assertOk();

        $pelanggan->update(['is_deleted' => 1]);

        $this->putJson("/api/customers/{$pelanggan->id}/point", [
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ])->assertStatus(404);
    }

    /**
     * `users_id` sengaja tidak dikirim: tabel `orders` di skema test tidak
     * punya kolom itu (lihat CreatesCustomerSchema), dan jalur yang diuji di
     * sini tidak membacanya.
     */
    private function buatPesanan(int $customerId, string $kode): void
    {
        \App\Models\Order::create([
            'projects_id' => 1,
            'customers_id' => $customerId,
            'code' => $kode,
            'date' => time(),
            'status' => 0,
            'is_deleted' => 0,
        ]);
    }

    public function test_daftar_pesanan_siap_jemput_memuat_id_dan_koordinat_pelanggan(): void
    {
        $this->masukSebagaiAdmin();

        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
        ]);
        $this->buatPesanan($pelanggan->id, 'UJI-001');

        $this->getJson('/api/sends/available-pickup-orders')
            ->assertOk()
            ->assertJsonPath('0.customer_id', $pelanggan->id)
            ->assertJsonPath('0.customer_latitude', -7.28848)
            ->assertJsonPath('0.customer_longitude', 112.6331921);
    }

    public function test_pelanggan_tanpa_titik_mengirim_koordinat_null(): void
    {
        $this->masukSebagaiAdmin();

        $pelanggan = $this->buatPelanggan();
        $this->buatPesanan($pelanggan->id, 'UJI-002');

        $this->getJson('/api/sends/available-pickup-orders')
            ->assertOk()
            ->assertJsonPath('0.customer_latitude', null)
            ->assertJsonPath('0.customer_longitude', null);
    }

    public function test_daftar_tunggu_jemput_memuat_id_dan_koordinat_pelanggan(): void
    {
        $this->masukSebagaiAdmin();

        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.5,112.5,17z',
        ]);
        $this->buatPesanan($pelanggan->id, 'UJI-003');

        $this->getJson('/api/sends/pickup-waiting-list')
            ->assertOk()
            ->assertJsonPath('data.0.customer_id', $pelanggan->id)
            ->assertJsonPath('data.0.customer_latitude', -7.5);
    }
}
