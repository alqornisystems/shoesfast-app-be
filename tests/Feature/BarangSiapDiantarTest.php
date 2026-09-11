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

    /** Kode pesanan barang di daftar antar, sebagaimana dilihat halaman delivery. */
    private function isiDaftarAntar(): array
    {
        $body = $this->getJson('/api/sends/delivery-waiting-list?page=1&per_page=100')
            ->assertStatus(200)
            ->json();

        return array_column($body['data'], 'id');
    }

    private function tugasAntar(OrderItem $item, int $isDeleted = 0): void
    {
        \App\Models\Send::withoutGlobalScopes()->insert([
            'projects_id' => 1, 'orders_id' => $item->orders_id,
            'orders_items_id' => $item->id, 'type' => 1, 'status' => 0,
            'date' => time(), 'is_deleted' => $isDeleted,
        ]);
    }

    /**
     * Data lama: ratusan barang selesai dikerjakan SEBELUM status barang ikut
     * naik, jadi kolom status-nya masih 0 atau 1. Daftar antar harus menghitung
     * kesiapan dari pekerjaannya, bukan dari kolom yang tertinggal itu.
     */
    public function test_barang_selesai_tampil_walau_kolom_statusnya_tertinggal(): void
    {
        foreach ([0, 1] as $statusLama) {
            [$item, $treatments] = $this->barang(1);
            Treatment::withoutGlobalScopes()->where('id', $treatments[0]->id)->update(['done_at' => time(), 'status' => 2]);
            OrderItem::withoutGlobalScope('branch')->where('id', $item->id)->update(['status' => $statusLama]);

            $this->assertContains($item->id, $this->isiDaftarAntar(), "status lama $statusLama harus tetap tampil");
        }
    }

    /** Kolom status yang terlanjur 2 tidak boleh meloloskan barang yang pekerjaannya belum selesai. */
    public function test_barang_yang_pekerjaannya_belum_selesai_tidak_tampil(): void
    {
        [$item] = $this->barang(1);
        OrderItem::withoutGlobalScope('branch')->where('id', $item->id)->update(['status' => 2]);

        $this->assertNotContains($item->id, $this->isiDaftarAntar());
    }

    public function test_barang_yang_sudah_dijadwalkan_antar_tidak_tampil_lagi(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);
        $this->tugasAntar($item);

        $this->assertNotContains($item->id, $this->isiDaftarAntar());
    }

    /** Tugas antarnya dihapus: barangnya harus kembali ke antrean, bukan hilang. */
    public function test_barang_kembali_ke_antrean_kalau_tugas_antarnya_dihapus(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);
        OrderItem::withoutGlobalScope('branch')->where('id', $item->id)->update(['status' => 3]);
        $this->tugasAntar($item, isDeleted: 1);

        $this->assertContains($item->id, $this->isiDaftarAntar());
    }

    /** Sudah sampai di tangan pelanggan: jangan pernah ditawarkan untuk diantar lagi. */
    public function test_barang_yang_sudah_sampai_tidak_ditawarkan_lagi(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);
        OrderItem::withoutGlobalScope('branch')->where('id', $item->id)->update(['status' => 4]);

        $this->assertNotContains($item->id, $this->isiDaftarAntar());
    }

    /**
     * Barang yatim: pesanannya sudah tidak ada. Tampil di daftar antar berarti
     * kurir disuruh mengantar barang yang tidak punya pelanggan maupun alamat.
     */
    public function test_barang_yang_pesanannya_sudah_tidak_ada_tidak_tampil(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);

        Order::withoutGlobalScopes()->where('id', $item->orders_id)->delete();

        $this->assertNotContains($item->id, $this->isiDaftarAntar());
    }

    public function test_barang_yang_pesanannya_dibatalkan_tidak_tampil(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);

        Order::withoutGlobalScopes()->where('id', $item->orders_id)->update(['is_deleted' => 1]);

        $this->assertNotContains($item->id, $this->isiDaftarAntar());
    }

    /**
     * Pekerjaannya tercatat di cabang lain dari barangnya. Kesiapan dinilai dari
     * pekerjaan milik barang itu, di cabang mana pun — bukan dari yang kebetulan
     * terlihat oleh cabang yang sedang aktif.
     */
    public function test_kesiapan_tidak_bergantung_cabang_yang_sedang_aktif(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);

        Treatment::withoutGlobalScopes()->where('id', $treatments[0]->id)->update(['projects_id' => 2]);
        Order::withoutGlobalScopes()->where('id', $item->orders_id)->update(['projects_id' => 2]);

        $this->assertContains($item->id, $this->isiDaftarAntar());
    }

    private function jadwalkanAntar(OrderItem $item)
    {
        // Kurir sungguhan di tabel users — `users_id` divalidasi `exists`.
        $kurir = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'kurir@uji.test'],
            ['name' => 'Kurir Uji', 'projects_id' => 1],
        );

        return $this->postJson('/api/sends', [
            'users_id' => $kurir->id,
            'orders_items_id' => $item->id,
            'date' => date('Y-m-d'),
            'type' => 1,
        ]);
    }

    /**
     * Daftar antar saja tidak cukup: tugas antar bisa dibuat dari form lain, dan
     * dua barang INV2026060323 dijadwalkan pulang padahal pekerjaannya masih
     * terbuka. Pagarnya harus di tempat tugas itu dibuat.
     */
    public function test_tugas_antar_ditolak_selama_pekerjaannya_belum_selesai(): void
    {
        [$item] = $this->barang(1);

        $this->jadwalkanAntar($item)
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'belum selesai'));
    }

    public function test_tugas_antar_diterima_setelah_semua_pekerjaan_selesai(): void
    {
        [$item, $treatments] = $this->barang(2);
        $this->selesaikan($treatments[0]);
        $this->jadwalkanAntar($item)->assertStatus(422);

        $this->selesaikan($treatments[1]);
        $this->jadwalkanAntar($item)->assertStatus(201);
    }

    /** Diselesaikan paksa dihitung selesai — itulah gunanya tombol itu. */
    public function test_selesai_paksa_membuka_jalan_ke_pengantaran(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->postJson('/api/treatments/force-complete', ['treatment_ids' => [$treatments[0]->id]])
            ->assertStatus(200);

        $this->jadwalkanAntar($item)->assertStatus(201);
    }

    public function test_tugas_antar_ditolak_kalau_pesanannya_sudah_tidak_ada(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);
        Order::withoutGlobalScopes()->where('id', $item->orders_id)->update(['is_deleted' => 1]);

        $this->jadwalkanAntar($item)->assertStatus(422);
    }

    /** Barang yang sudah dijadwalkan tidak boleh dapat tugas antar kedua. */
    public function test_tugas_antar_ditolak_kalau_sudah_dijadwalkan(): void
    {
        [$item, $treatments] = $this->barang(1);
        $this->selesaikan($treatments[0]);
        $this->jadwalkanAntar($item)->assertStatus(201);

        $this->jadwalkanAntar($item)->assertStatus(422);
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
