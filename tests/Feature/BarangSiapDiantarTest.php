<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Role;
use App\Models\Service;
use App\Models\Treatment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Customer\CreatesCustomerSchema;
use Tests\TestCase;

/**
 * Barang yang selesai dikerjakan harus sampai ke antrean pengantaran.
 *
 * `sends/delivery-waiting-list` menyaring `orders_items.status = 2`, dan sampai
 * hari ini TIDAK ADA satu pun kode yang menyetelnya — menyelesaikan pengerjaan
 * hanya menyentuh baris `treatments`. Akibatnya barang selesai menumpuk tanpa
 * pernah dijadwalkan pulang, dan tidak ada galat di mana pun: daftarnya sekadar
 * tampak sepi. Uji ini yang menahan supaya jalur itu tidak putus lagi.
 */
class BarangSiapDiantarTest extends TestCase
{
    use CreatesCustomerSchema;

    private const SIAP_ANTAR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);

        $admin = new User(['name' => 'Admin Uji', 'projects_id' => 1]);
        $admin->id = 1;
        $admin->setRelation('role', new Role(['name' => 'Admin']));
        Sanctum::actingAs($admin);
    }

    /** @return array{OrderItem, list<Treatment>} */
    private function barang(int $jumlahTreatment): array
    {
        $service = Service::firstOrCreate(['name' => 'Cuci Sepatu'], ['estimation' => 3, 'price' => 50000]);

        $customer = Customer::withoutGlobalScope('branch')->firstOrCreate(
            ['phone' => '81200001111'],
            ['projects_id' => 1, 'name' => 'Budi'],
        );

        $order = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $customer->id,
            'code' => 'INV'.uniqid(), 'date' => time(),
            'total_price' => 50000, 'status' => 1,
        ]);

        $item = OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'name' => 'Sepatu Putih', 'status' => 0,
        ]);

        $treatments = [];
        for ($i = 0; $i < $jumlahTreatment; $i++) {
            $treatments[] = Treatment::withoutGlobalScope('branch')->create([
                'projects_id' => 1,
                'orders_items_id' => $item->id,
                'services_id' => $service->id,
                'status' => 1,
                'date_start' => time(),
                'price' => 50000,
            ]);
        }

        return [$item, $treatments];
    }

    private function selesaikan(Treatment $treatment): void
    {
        $this->putJson("/api/treatments/{$treatment->id}/status", ['status' => 2])
            ->assertStatus(200);
    }

    private function statusBarang(OrderItem $item): int
    {
        return (int) OrderItem::withoutGlobalScope('branch')->find($item->id)->status;
    }

    public function test_barang_dengan_satu_pekerjaan_siap_diantar_begitu_selesai(): void
    {
        [$item, $treatments] = $this->barang(1);

        $this->assertSame(0, $this->statusBarang($item));

        $this->selesaikan($treatments[0]);

        $this->assertSame(self::SIAP_ANTAR, $this->statusBarang($item));
    }

    /**
     * Sepatu dengan cuci + repaint tidak boleh masuk antrean antar setelah
     * cucinya saja — barangnya akan pulang setengah jadi.
     */
    public function test_barang_menunggu_pekerjaan_terakhirnya(): void
    {
        [$item, $treatments] = $this->barang(2);

        $this->selesaikan($treatments[0]);
        $this->assertSame(0, $this->statusBarang($item), 'Baru satu pekerjaan selesai; barang belum boleh siap antar.');

        $this->selesaikan($treatments[1]);
        $this->assertSame(self::SIAP_ANTAR, $this->statusBarang($item));
    }

    /**
     * Barang yang sudah berangkat (3) atau sudah sampai (4) tidak boleh ditarik
     * mundur ke antrean hanya karena satu treatment lama baru ditutup
     * belakangan — kurirnya akan disuruh mengantar barang yang sudah di tangan
     * pelanggan.
     */
    public function test_barang_yang_sudah_diantar_tidak_ditarik_mundur(): void
    {
        foreach ([3, 4] as $status) {
            [$item, $treatments] = $this->barang(1);
            OrderItem::withoutGlobalScope('branch')->where('id', $item->id)->update(['status' => $status]);

            $this->selesaikan($treatments[0]);

            $this->assertSame($status, $this->statusBarang($item), "Status $status tidak boleh berubah.");
        }
    }

    public function test_mengembalikan_pekerjaan_ke_teknisi_tidak_menyiapkan_barang(): void
    {
        [$item, $treatments] = $this->barang(1);

        $this->putJson("/api/treatments/{$treatments[0]->id}/status", ['status' => 0])
            ->assertStatus(200);

        $this->assertSame(0, $this->statusBarang($item));
    }

    public function test_selesai_paksa_juga_menyiapkan_barangnya(): void
    {
        [$item, $treatments] = $this->barang(1);

        $this->postJson('/api/treatments/force-complete', [
            'treatment_ids' => [$treatments[0]->id],
        ])->assertStatus(200);

        $this->assertSame(self::SIAP_ANTAR, $this->statusBarang($item));
    }
}
