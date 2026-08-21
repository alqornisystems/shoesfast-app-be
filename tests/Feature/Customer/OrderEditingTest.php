<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Send;
use App\Models\Service;
use App\Models\Treatment;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menyunting pesanan yang masih berjalan, dan meminta barangnya dibawa pulang.
 *
 * Batas yang diuji di sini satu kalimat: yang belum disentuh bengkel masih milik
 * pelanggan, yang sudah di rak milik petugas.
 */
class OrderEditingTest extends TestCase
{
    use CreatesCustomerSchema;

    private Customer $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create([
            'name' => 'Shoesfast Pusat',
            'latitude' => -8.00784990, 'longitude' => 112.61261450,
        ]);

        $this->customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'address' => 'Jl. Melati 10', 'latitude' => -7.99, 'longitude' => 112.62,
        ]);

        $this->order = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $this->customer->id,
            'code' => 'INV2026080001', 'date' => time() - 86400,
            'total_price' => 0, 'status' => 1,
        ]);

        Sanctum::actingAs($this->customer, ['*'], 'customer');
    }

    private function barang(string $nama, int $status = 0, int $harga = 0): OrderItem
    {
        return OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $this->order->id,
            'name' => $nama, 'type' => 2, 'price' => $harga, 'status' => $status,
        ]);
    }

    private function layanan(string $nama = 'Deep Clean', int $harga = 50000): Service
    {
        return Service::create(['name' => $nama, 'price' => $harga, 'hpp' => 0, 'estimation' => 3]);
    }

    /** Hari kerja berikutnya, supaya test tidak pecah tiap kali dijalankan hari Minggu. */
    private function hariBuka(): string
    {
        $t = strtotime('+1 day');

        while (date('N', $t) === '7') {
            $t += 86400;
        }

        return date('Y-m-d', $t);
    }

    // ── Tambah barang di tengah pesanan ──────────────────────────────────────

    public function test_barang_bisa_ditambahkan_ke_pesanan_yang_masih_berjalan(): void
    {
        $service = $this->layanan();

        $this->postJson("/api/customer/orders/{$this->order->id}/items", [
            'type' => 2,
            'name' => 'Sepatu tambahan',
            'services' => [$service->id],
            'arrival' => ['method' => 'jemput', 'date' => $this->hariBuka()],
        ])->assertCreated();

        $item = OrderItem::withoutGlobalScope('branch')->where('name', 'Sepatu tambahan')->first();

        $this->assertNotNull($item);
        $this->assertSame(1, Treatment::withoutGlobalScope('branch')->where('orders_items_id', $item->id)->count());

        // Barang tambahan belum ada di bengkel, jadi penjemputannya ikut dibuatkan.
        $this->assertSame(1, Send::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)->where('type', 0)->count());
    }

    public function test_barang_tidak_bisa_ditambahkan_ke_pesanan_yang_sudah_selesai(): void
    {
        $this->order->forceFill(['status' => 3])->saveQuietly();

        $this->postJson("/api/customer/orders/{$this->order->id}/items", [
            'type' => 2, 'name' => 'Terlambat',
            'arrival' => ['method' => 'antar_sendiri'],
        ])->assertStatus(422);
    }

    public function test_tanggal_jemput_barang_tambahan_tidak_boleh_hari_tutup(): void
    {
        $this->postJson("/api/customer/orders/{$this->order->id}/items", [
            'type' => 2, 'name' => 'Sepatu',
            'arrival' => ['method' => 'jemput', 'date' => date('Y-m-d', strtotime('next sunday'))],
        ])->assertStatus(422);
    }

    // ── Ubah nama dan layanan ────────────────────────────────────────────────

    public function test_nama_bisa_diubah_selama_barang_belum_selesai(): void
    {
        $item = $this->barang('Nama lama', status: 1);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'name' => 'Nama baru',
        ])->assertOk();

        $this->assertSame('Nama baru', $item->fresh()->name);
    }

    public function test_nama_terkunci_setelah_barang_selesai(): void
    {
        $item = $this->barang('Nama lama', status: 2);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'name' => 'Nama baru',
        ])->assertStatus(422);

        $this->assertSame('Nama lama', $item->fresh()->name);
    }

    public function test_foto_bisa_diganti_selama_barang_belum_selesai(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Ekstensi GD tidak tersedia.');
        }

        \Illuminate\Support\Facades\Storage::fake('public');

        $item = $this->barang('Sepatu', status: 1);
        $item->update(['photo' => 'orders_items/lama.jpg']);

        $img = imagecreatetruecolor(600, 400);
        ob_start();
        imagejpeg($img, null, 85);
        $dataUrl = 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        imagedestroy($img);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'photo' => $dataUrl,
        ])->assertOk();

        $baru = $item->fresh()->photo;

        $this->assertNotSame('orders_items/lama.jpg', $baru);
        $this->assertStringStartsWith('orders_items/', $baru);
    }

    public function test_foto_bisa_dihapus(): void
    {
        $item = $this->barang('Sepatu', status: 1);
        $item->update(['photo' => 'orders_items/lama.jpg']);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'photo' => null,
        ])->assertOk();

        $this->assertNull($item->fresh()->photo);
    }

    public function test_foto_terkunci_setelah_barang_selesai(): void
    {
        $item = $this->barang('Sepatu', status: 2);
        $item->update(['photo' => 'orders_items/lama.jpg']);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'photo' => null,
        ])->assertStatus(422);

        $this->assertSame('orders_items/lama.jpg', $item->fresh()->photo);
    }

    /** Inti permintaannya: menambah pekerjaan saat barangnya sedang dikerjakan. */
    public function test_layanan_bisa_ditambahkan_di_tengah_pengerjaan(): void
    {
        $item = $this->barang('Sepatu', status: 1);
        $lama = $this->layanan('Cuci', 75000);
        $baru = $this->layanan('Ganti sol', 195000);

        Treatment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_items_id' => $item->id, 'services_id' => $lama->id,
            'price' => 75000, 'status' => 2, 'done_at' => time() - 3600,
        ]);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'add_services' => [$baru->id],
        ])->assertOk();

        $harga = Treatment::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)
            ->where('services_id', $baru->id)
            ->value('price');

        // Harga dari katalog, tidak pernah dari permintaan.
        $this->assertSame(195000, (int) $harga);

        // Yang lama tetap ada. Menambah tidak pernah menghapus.
        $this->assertSame(2, Treatment::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)->count());
    }

    /** Layanan yang sama boleh dua kali: dua pasang sol, dua kali cuci. */
    public function test_layanan_yang_sama_boleh_ditambahkan_dua_kali(): void
    {
        $item = $this->barang('Sepatu', status: 1);
        $service = $this->layanan('Cuci', 75000);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'add_services' => [$service->id, $service->id],
        ])->assertOk();

        $this->assertSame(2, Treatment::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)->count());
    }

    /**
     * Endpoint ini TIDAK BISA mencabut layanan, dan itu disengaja. Kalau daftar yang
     * dikirim klien menentukan nasib baris yang sudah ada, satu ketukan salah mencabut
     * pekerjaan yang sudah diantrekan teknisi tanpa pelanggan tahu apa yang hilang.
     */
    public function test_layanan_lama_tidak_pernah_tercabut(): void
    {
        $item = $this->barang('Sepatu', status: 1);
        $service = $this->layanan('Cuci', 75000);

        $antre = Treatment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_items_id' => $item->id, 'services_id' => $service->id,
            'price' => 75000, 'status' => 0,
        ]);

        $this->patchJson("/api/customer/orders/{$this->order->id}/items/{$item->id}", [
            'add_services' => [],
        ])->assertOk();

        $this->assertSame(0, (int) Treatment::withoutGlobalScopes()->find($antre->id)->is_deleted);
    }

    // ── Hapus barang ─────────────────────────────────────────────────────────

    public function test_barang_yang_belum_disentuh_boleh_dihapus(): void
    {
        $item = $this->barang('Salah pesan');

        $this->deleteJson("/api/customer/orders/{$this->order->id}/items/{$item->id}")->assertOk();

        // fresh() ikut memakai global scope is_deleted=0, jadi barang yang berhasil
        // dihapus justru terbaca null. Dibaca eksplisit tanpa scope.
        $this->assertSame(1, (int) OrderItem::withoutGlobalScopes()->find($item->id)->is_deleted);
    }

    public function test_barang_yang_sudah_dikerjakan_tidak_boleh_dihapus(): void
    {
        $item = $this->barang('Sedang dikerjakan', status: 1);

        $this->deleteJson("/api/customer/orders/{$this->order->id}/items/{$item->id}")
            ->assertStatus(422);

        $this->assertSame(0, (int) OrderItem::withoutGlobalScopes()->find($item->id)->is_deleted);
    }

    public function test_barang_milik_orang_lain_tidak_bisa_disentuh(): void
    {
        $orang = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Orang lain', 'phone' => '81299998888',
        ]);
        $pesananOrang = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $orang->id,
            'code' => 'INV2026080999', 'date' => time(), 'status' => 1,
        ]);
        $barangOrang = OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $pesananOrang->id,
            'name' => 'Punya orang', 'type' => 2, 'status' => 0,
        ]);

        $this->patchJson("/api/customer/orders/{$pesananOrang->id}/items/{$barangOrang->id}", [
            'name' => 'Dibajak',
        ])->assertStatus(404);

        $this->assertSame('Punya orang', $barangOrang->fresh()->name);
    }

    // ── Permintaan ambil barang ──────────────────────────────────────────────

    public function test_barang_siap_dan_lunas_bisa_diminta_diambil(): void
    {
        $item = $this->barang('Sepatu siap', status: 2, harga: 100000);

        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $this->order->id,
            'date' => time(), 'nominal' => 100000,
        ]);

        $this->postJson("/api/customer/orders/{$this->order->id}/handover", [
            'items' => [$item->id],
            'method' => 'ambil_sendiri',
            'date' => $this->hariBuka(),
        ])->assertCreated();

        $send = Send::withoutGlobalScope('branch')->where('orders_items_id', $item->id)->first();

        $this->assertNotNull($send);
        // Permintaan, bukan penugasan: kurirnya masih kosong dan statusnya menunggu.
        $this->assertSame(0, (int) $send->users_id);
        $this->assertSame(0, (int) $send->status);
        $this->assertSame(1, (int) $send->type);
    }

    public function test_barang_yang_belum_lunas_tidak_bisa_diminta(): void
    {
        $item = $this->barang('Sepatu siap', status: 2, harga: 100000);

        $this->postJson("/api/customer/orders/{$this->order->id}/handover", [
            'items' => [$item->id], 'method' => 'ambil_sendiri',
        ])->assertStatus(422);

        $this->assertSame(0, Send::withoutGlobalScope('branch')->count());
    }

    public function test_barang_yang_belum_selesai_tidak_bisa_diminta(): void
    {
        $item = $this->barang('Masih dikerjakan', status: 1, harga: 100000);

        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $this->order->id,
            'date' => time(), 'nominal' => 100000,
        ]);

        $this->postJson("/api/customer/orders/{$this->order->id}/handover", [
            'items' => [$item->id], 'method' => 'diantar',
        ])->assertStatus(422);
    }

    public function test_tanggal_ambil_tidak_boleh_hari_tutup(): void
    {
        $item = $this->barang('Sepatu siap', status: 2, harga: 100000);

        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $this->order->id,
            'date' => time(), 'nominal' => 100000,
        ]);

        $this->postJson("/api/customer/orders/{$this->order->id}/handover", [
            'items' => [$item->id],
            'method' => 'ambil_sendiri',
            'date' => date('Y-m-d', strtotime('next sunday')),
        ])->assertStatus(422);
    }
}
