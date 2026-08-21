<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Barang yang pernah dititipkan (GET /api/customer/items).
 *
 * Dipakai layar pesan supaya pelanggan tidak mengetik ulang sepatu yang sama tiap kali.
 * Karena itu isinya harus milik dia sendiri, dan tidak boleh mengulang barang yang sama
 * berkali-kali sampai mendorong barang lain keluar layar.
 */
class CustomerItemsTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
    }

    private function pelanggan(string $phone = '81200001111'): Customer
    {
        return Customer::create([
            'projects_id' => 1,
            'name' => 'Budi',
            'phone' => $phone,
        ]);
    }

    private function titip(Customer $c, string $nama, int $type = 2, ?string $photo = null): OrderItem
    {
        $order = Order::create([
            'projects_id' => 1,
            'customers_id' => $c->id,
            'code' => 'INV'.uniqid(),
            'date' => time(),
            'status' => 0,
        ]);

        return OrderItem::create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'name' => $nama,
            'type' => $type,
            'photo' => $photo,
            'status' => 0,
        ]);
    }

    /**
     * Inti daftar ini: memesan lagi untuk barang yang sama TIDAK boleh menyuruh
     * pelanggan mengisi ulang apa pun. Jadi kelengkapan dan id barang terakhir ikut
     * terkirim — yang pertama mengisi centangnya, yang kedua dipakai server menyalin
     * fotonya tanpa klien perlu mengirim balik URL apa pun.
     */
    public function test_membawa_kelengkapan_dan_id_barang_terakhir(): void
    {
        $c = $this->pelanggan();
        $lama = $this->titip($c, 'AF1 putih');
        $lama->update(['checkbox' => 'true, false, true']);

        Sanctum::actingAs($c, ['*'], 'customer');
        $data = $this->getJson('/api/customer/items')->assertOk()->json('data');

        $this->assertSame($lama->id, $data[0]['id']);
        $this->assertSame([true, false, true], $data[0]['kelengkapan']);
    }

    /**
     * Pengelompokan harus tahan terhadap riwayat panjang. Dulu hanya 24 baris
     * TERAKHIR yang dipindai lalu baru dikelompokkan, jadi pelanggan yang rajin
     * menitipkan kehilangan sepatu lamanya dari daftar meski barangnya masih ada dan
     * masih rutin dicuci.
     */
    public function test_barang_lama_tetap_muncul_di_balik_riwayat_panjang(): void
    {
        $c = $this->pelanggan();
        $this->titip($c, 'Sepatu andalan');

        // 30 titipan berikutnya, tapi cuma dua barang — persis pola pelanggan rutin.
        for ($i = 0; $i < 30; $i++) {
            $this->titip($c, $i % 2 === 0 ? 'Tas kerja' : 'Koper kabin');
        }

        Sanctum::actingAs($c, ['*'], 'customer');
        $nama = array_column($this->getJson('/api/customer/items')->assertOk()->json('data'), 'name');

        $this->assertContains('Sepatu andalan', $nama);
        $this->assertCount(3, $nama);
    }

    public function test_titipan_berulang_dihitung_bukan_diulang(): void
    {
        $c = $this->pelanggan();
        $this->titip($c, 'AF1 putih');
        $this->titip($c, 'af1 PUTIH');
        $this->titip($c, 'AF1 putih');

        Sanctum::actingAs($c, ['*'], 'customer');
        $data = $this->getJson('/api/customer/items')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(3, $data[0]['times']);
    }

    public function test_membawa_barang_sendiri_beserta_fotonya(): void
    {
        $me = $this->pelanggan();
        $this->titip($me, 'Nike Air Force 1', 2, 'orders_items/af1.jpg');

        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/items')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nike Air Force 1')
            ->assertJsonPath('data.0.type', 2)
            ->assertJsonPath('data.0.photo', asset('storage/orders_items/af1.jpg'));
    }

    /** Sepatu yang sudah lima kali dicuci tidak perlu muncul lima kali. */
    public function test_barang_yang_sama_hanya_muncul_sekali(): void
    {
        $me = $this->pelanggan();
        $this->titip($me, 'Nike Air Force 1');
        $this->titip($me, 'nike air force 1'); // beda huruf besar-kecil, barang yang sama
        $this->titip($me, 'Adidas Samba');

        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/items')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_barang_pelanggan_lain_tidak_ikut(): void
    {
        $me = $this->pelanggan('81200001111');
        $orang_lain = $this->pelanggan('81200002222');

        $this->titip($me, 'Punya saya');
        $this->titip($orang_lain, 'Punya orang lain');

        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/items')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Punya saya');
    }

    public function test_pelanggan_baru_dapat_daftar_kosong_bukan_galat(): void
    {
        $me = $this->pelanggan();
        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/items')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
