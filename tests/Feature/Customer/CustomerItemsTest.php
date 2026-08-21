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
