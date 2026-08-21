<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Send;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Paginasi daftar pengiriman (GET /api/sends/in-progress dan /history).
 *
 * Bentuknya sengaja dibuat sama persis dengan GET /treatments: satu pola paginator untuk
 * seluruh daftar, bukan dua yang harus diingat mana dipakai di mana.
 *
 * Yang paling penting dijaga di sini adalah urutan yang stabil. Tanpa pemecah seri, dua
 * baris bertanggal sama bisa bertukar tempat antar permintaan — sehingga satu baris muncul
 * dua kali di halaman berikutnya sementara baris lain hilang sama sekali, dan kurir tidak
 * akan pernah tahu tugasnya terlewat.
 */
class PaginasiPengirimanTest extends TestCase
{
    use CreatesFieldTaskSchema;

    private const KURIR = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFieldTaskSchema();
    }

    private function actingAsKurir(): void
    {
        $user = new User(['name' => 'Kurir Uji', 'projects_id' => 1]);
        $user->id = self::KURIR;
        $user->setRelation('role', new Role(['name' => 'Kurir']));

        Sanctum::actingAs($user);
    }

    /** Semua bertanggal SAMA — justru itu yang menguji kestabilan urutan. */
    private function buatTugas(int $jumlah, int $status = Send::STATUS_BERJALAN): void
    {
        $tanggal = strtotime('2026-08-13 09:00');

        for ($i = 0; $i < $jumlah; $i++) {
            $order = Order::create([
                'projects_id' => 1, 'customers_id' => 1, 'code' => 'INV'.$i,
                'date' => $tanggal, 'status' => 0, 'total_price' => 100000,
            ]);

            Send::create([
                'projects_id' => 1, 'users_id' => self::KURIR, 'orders_id' => $order->id,
                'date' => $tanggal, 'status' => $status, 'type' => 1,
                'modified_at' => $tanggal,
            ]);
        }
    }

    public function test_in_progress_mengembalikan_bentuk_paginator(): void
    {
        $this->actingAsKurir();
        $this->buatTugas(25);

        $this->getJson('/api/sends/in-progress')
            ->assertStatus(200)
            ->assertJsonStructure(['current_page', 'data', 'last_page', 'per_page', 'total'])
            ->assertJsonPath('total', 25)
            ->assertJsonPath('per_page', 15)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(15, 'data');
    }

    public function test_history_juga_paginator_dan_tetap_menerima_rentang_tanggal(): void
    {
        $this->actingAsKurir();
        $this->buatTugas(20, Send::STATUS_SELESAI);

        $this->getJson('/api/sends/history?start_date=2026-08-01&end_date=2026-08-31&per_page=5')
            ->assertStatus(200)
            ->assertJsonPath('total', 20)
            ->assertJsonPath('per_page', 5)
            ->assertJsonPath('last_page', 4)
            ->assertJsonCount(5, 'data');
    }

    public function test_per_page_dihormati_dan_dibatasi(): void
    {
        $this->actingAsKurir();
        $this->buatTugas(5);

        $this->getJson('/api/sends/in-progress?per_page=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        // Batas atas menjaga satu permintaan tidak menarik seluruh tabel.
        $this->getJson('/api/sends/in-progress?per_page=500')->assertStatus(422);
    }

    /**
     * Inti dari paginasi yang benar: dua halaman tidak boleh punya baris yang sama, dan
     * gabungannya harus utuh. Seluruh baris di sini bertanggal identik, jadi kalau urutan
     * hanya bersandar pada `date`, test ini yang akan menangkapnya.
     */
    public function test_halaman_tidak_tumpang_tindih_dan_tidak_ada_yang_hilang(): void
    {
        $this->actingAsKurir();
        $this->buatTugas(20);

        $satu = $this->getJson('/api/sends/in-progress?per_page=10&page=1')->json('data');
        $dua = $this->getJson('/api/sends/in-progress?per_page=10&page=2')->json('data');

        $idSatu = array_column($satu, 'id');
        $idDua = array_column($dua, 'id');

        $this->assertEmpty(array_intersect($idSatu, $idDua), 'Ada baris yang muncul di dua halaman.');
        $this->assertCount(20, array_unique(array_merge($idSatu, $idDua)), 'Ada baris yang hilang.');
    }

    /**
     * Koordinat tujuan dikirim sebagai angka, bukan hanya URL Google Maps.
     *
     * Mengurai @lat,lng dari string URL berhasil sampai suatu hari formatnya berubah dan
     * peta menaruh tujuan di tengah laut — dan yang mengurai akan berbeda antara halaman
     * pelanggan dan aplikasi kurir, lalu berbeda hasilnya.
     */
    public function test_baris_membawa_koordinat_tujuan_sebagai_angka(): void
    {
        $this->actingAsKurir();

        $pelanggan = \App\Models\Customer::create([
            'projects_id' => 1,
            'name' => 'Budi',
            'phone' => '81200001111',
            'latitude' => -7.9553004,
            'longitude' => 112.5873766,
        ]);

        $order = Order::create([
            'projects_id' => 1, 'customers_id' => $pelanggan->id, 'code' => 'INVKOORD',
            'date' => time(), 'status' => 0, 'total_price' => 100000,
        ]);
        Send::create([
            'projects_id' => 1, 'users_id' => self::KURIR, 'orders_id' => $order->id,
            'date' => time(), 'status' => Send::STATUS_BERJALAN, 'type' => 1,
        ]);

        $baris = $this->getJson('/api/sends/in-progress')->assertStatus(200)->json('data.0');

        $this->assertIsFloat($baris['customer_latitude']);
        $this->assertEqualsWithDelta(-7.9553004, $baris['customer_latitude'], 0.0000001);
        $this->assertEqualsWithDelta(112.5873766, $baris['customer_longitude'], 0.0000001);
    }

    /** Pelanggan yang belum pernah menaruh titik peta tetap boleh punya tugas. */
    public function test_koordinat_null_kalau_pelanggan_belum_menaruh_titik(): void
    {
        $this->actingAsKurir();
        $this->buatTugas(1);

        $baris = $this->getJson('/api/sends/in-progress')->assertStatus(200)->json('data.0');

        $this->assertNull($baris['customer_latitude']);
        $this->assertNull($baris['customer_longitude']);
    }

    /** Isi tiap baris tidak berubah — hanya pembungkusnya. */
    public function test_isi_baris_tetap_sama_termasuk_status_pembayaran(): void
    {
        $this->actingAsKurir();
        $this->buatTugas(1);

        $baris = $this->getJson('/api/sends/in-progress')->json('data.0');

        foreach (['id', 'date', 'type', 'type_label', 'status', 'orders_id', 'payment_status', 'customer_name'] as $kunci) {
            $this->assertArrayHasKey($kunci, $baris);
        }
    }
}
