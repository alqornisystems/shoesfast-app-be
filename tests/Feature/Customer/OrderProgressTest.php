<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Holiday;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Send;
use App\Models\Service;
use App\Models\Treatment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Perjalanan pesanan dilihat per barang.
 *
 * Satu pesanan bisa berisi tiga pasang sepatu yang selesai di hari berbeda. Yang
 * diuji di sini justru itu: bahwa keadaan tiap barang tidak lagi tenggelam di balik
 * satu status pesanan.
 */
class OrderProgressTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        // Titik cabang wajib ada: tanpa itu penilaian jemput berhenti di
        // "tanpa koordinat" dan pemeriksaan hari kerja tidak pernah kesampaian.
        Project::create([
            'name' => 'Shoesfast Pusat',
            'latitude' => -8.00784990, 'longitude' => 112.61261450,
        ]);
    }

    private function pelanggan(): Customer
    {
        return Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
        ]);
    }

    private function pesanan(Customer $c): Order
    {
        return Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $c->id,
            'code' => 'INV2026080001', 'date' => time() - 5 * 86400,
            'total_price' => 0, 'status' => 1,
        ]);
    }

    private function barang(Order $order, string $nama, int $harga, int $status): OrderItem
    {
        return OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'name' => $nama, 'type' => 2, 'price' => $harga, 'status' => $status,
        ]);
    }

    private function detail(Order $order): array
    {
        return $this->getJson("/api/customer/orders/{$order->id}")->assertOk()->json();
    }

    public function test_siap_diambil_hanya_kalau_semua_barang_siap(): void
    {
        $c = $this->pelanggan();
        Sanctum::actingAs($c, ['*'], 'customer');
        $order = $this->pesanan($c);

        $this->barang($order, 'Sepatu A', 100000, 2);
        $this->barang($order, 'Sepatu B', 100000, 1);

        $d = $this->detail($order);
        $this->assertSame('Sebagian siap diambil (1 dari 2)', $d['status_label']);
        $this->assertSame(1, $d['progress']['ready']);

        OrderItem::withoutGlobalScope('branch')->where('name', 'Sepatu B')->update(['status' => 2]);

        $this->assertSame('Siap diambil', $this->detail($order)['status_label']);
    }

    /**
     * Inti permintaannya: satu barang boleh dibawa pulang tanpa menunggu barang lain,
     * asal tagihan barang ITU sudah lunas.
     */
    public function test_barang_boleh_diambil_kalau_tagihannya_sendiri_lunas(): void
    {
        $c = $this->pelanggan();
        Sanctum::actingAs($c, ['*'], 'customer');
        $order = $this->pesanan($c);

        $this->barang($order, 'Sepatu A', 100000, 2);
        $this->barang($order, 'Sepatu B', 250000, 2);

        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'date' => time(), 'nominal' => 100000,
        ]);

        $items = collect($this->detail($order)['items'])->keyBy('name');

        $this->assertTrue($items['Sepatu A']['progress']['can_take']);
        $this->assertSame(0, $items['Sepatu A']['progress']['credit']);

        $this->assertFalse($items['Sepatu B']['progress']['can_take']);
        $this->assertSame(250000, $items['Sepatu B']['progress']['credit']);
    }

    /** Barang yang belum selesai tidak boleh diambil meski uangnya sudah cukup. */
    public function test_barang_yang_belum_selesai_tidak_bisa_diambil(): void
    {
        $c = $this->pelanggan();
        Sanctum::actingAs($c, ['*'], 'customer');
        $order = $this->pesanan($c);
        $this->barang($order, 'Sepatu A', 100000, 1);

        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'date' => time(), 'nominal' => 100000,
        ]);

        $this->assertFalse($this->detail($order)['items'][0]['progress']['can_take']);
    }

    public function test_riwayat_menyebut_siapa_yang_mengerjakan(): void
    {
        $c = $this->pelanggan();
        Sanctum::actingAs($c, ['*'], 'customer');
        $order = $this->pesanan($c);
        $item = $this->barang($order, 'Sepatu A', 100000, 2);

        $teknisi = new User(['name' => 'Revan']);
        $teknisi->save();
        $service = Service::create(['name' => 'Deep Clean', 'price' => 50000, 'hpp' => 0, 'estimation' => 5]);

        Treatment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_items_id' => $item->id, 'services_id' => $service->id,
            'users_id' => $teknisi->id, 'price' => 50000, 'status' => 2, 'done_at' => time() - 86400,
        ]);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'users_id' => $teknisi->id,
            'date' => time() - 4 * 86400, 'type' => 0, 'status' => 1,
        ]);

        $riwayat = collect($this->detail($order)['items'][0]['progress']['history'])->keyBy('label');

        $this->assertSame('Dikerjakan Revan', $riwayat['Deep Clean']['detail']);
        $this->assertSame('Oleh Revan', $riwayat['Dijemput kurir']['detail']);
    }

    public function test_posisi_barang_ikut_dikirim(): void
    {
        $c = $this->pelanggan();
        Sanctum::actingAs($c, ['*'], 'customer');
        $order = $this->pesanan($c);
        $this->barang($order, 'Sepatu A', 100000, 2);

        $this->assertSame(
            'Selesai, menunggu diambil di Bengkel Shoesfast Pusat',
            $this->detail($order)['items'][0]['progress']['location']
        );
    }

    /** Kurir tidak berangkat hari Minggu. Menerimanya diam-diam berarti menjanjikan penjemputan yang tidak terjadi. */
    public function test_tanggal_jemput_hari_minggu_ditolak(): void
    {
        $c = $this->pelanggan();
        $c->update(['latitude' => -7.99, 'longitude' => 112.62, 'address' => 'Jl. Melati 10']);
        Sanctum::actingAs($c, ['*'], 'customer');

        $minggu = strtotime('next sunday');

        $galat = $this->postJson('/api/customer/orders', [
            'items' => [['type' => 2, 'name' => 'Sepatu A']],
            'pickup' => ['method' => 'jemput', 'date' => date('Y-m-d', $minggu)],
        ])->assertStatus(422)->json('errors');

        $this->assertSame('Minggu libur, kurir tidak berangkat.', $galat['pickup.date'][0]);
    }

    public function test_tanggal_merah_juga_ditolak(): void
    {
        $c = $this->pelanggan();
        $c->update(['latitude' => -7.99, 'longitude' => 112.62, 'address' => 'Jl. Melati 10']);
        Sanctum::actingAs($c, ['*'], 'customer');

        // Senin depan dijadikan tanggal merah.
        $senin = strtotime('next monday');
        Holiday::withoutGlobalScope('branch')->create([
            'projects_id' => null, 'date' => $senin, 'name' => 'Hari Kemerdekaan',
        ]);

        $galat = $this->postJson('/api/customer/orders', [
            'items' => [['type' => 2, 'name' => 'Sepatu A']],
            'pickup' => ['method' => 'jemput', 'date' => date('Y-m-d', $senin)],
        ])->assertStatus(422)->json('errors');

        $this->assertSame('Hari Kemerdekaan, kurir tidak berangkat.', $galat['pickup.date'][0]);
    }
}
