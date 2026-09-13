<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRM tahap 1: segmen, profil pelanggan, catatan, dan tag.
 *
 * Segmen dihitung dari pesanan setiap kali diminta. Yang dijaga di sini adalah
 * urutan aturannya — kebaruan dinilai lebih dulu dari nilai belanja — dan
 * bahwa pesanan dari cabang lain ikut terhitung.
 */
class CustomerCrmTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
        Project::create(['name' => 'Cabang Surabaya']);
        $this->masukSebagai('Admin');
    }

    private function masukSebagai(string $role): void
    {
        $user = new User(['name' => 'Admin Uji', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => $role]));
        Sanctum::actingAs($user);
    }

    private function pelanggan(string $nama, string $hp = '81200001111'): Customer
    {
        return Customer::create(['projects_id' => 1, 'name' => $nama, 'phone' => $hp]);
    }

    /** Pesanan $hariLalu hari yang lalu. */
    private function pesanan(Customer $c, int $hariLalu, int $total = 100000, int $cabang = 1, int $status = 1): Order
    {
        return Order::withoutGlobalScope('branch')->create([
            'projects_id' => $cabang, 'customers_id' => $c->id,
            'code' => 'INV'.uniqid(), 'date' => time() - $hariLalu * 86400,
            'total_price' => $total, 'status' => $status,
        ]);
    }

    private function segmen(Customer $c): string
    {
        return $this->getJson("/api/customers/{$c->id}/profile")
            ->assertStatus(200)
            ->json('data.summary.segment');
    }

    public function test_segmen_mengikuti_aturannya(): void
    {
        $belum = $this->pelanggan('Belum');
        $baru = $this->pelanggan('Baru');
        $this->pesanan($baru, 3);
        $aktif = $this->pelanggan('Aktif');
        $this->pesanan($aktif, 3);
        $this->pesanan($aktif, 10);
        $loyal = $this->pelanggan('Loyal');
        foreach (range(1, 5) as $i) {
            $this->pesanan($loyal, $i);
        }
        $vip = $this->pelanggan('Vip');
        $this->pesanan($vip, 5, 2500000);
        $mauHilang = $this->pelanggan('Mau Hilang');
        $this->pesanan($mauHilang, 90);
        $tidur = $this->pelanggan('Tidur');
        $this->pesanan($tidur, 200, 9000000);

        $this->assertSame('belum_order', $this->segmen($belum));
        $this->assertSame('baru', $this->segmen($baru));
        $this->assertSame('aktif', $this->segmen($aktif));
        $this->assertSame('loyal', $this->segmen($loyal));
        $this->assertSame('vip', $this->segmen($vip));
        $this->assertSame('mau_hilang', $this->segmen($mauHilang));
        // Belanja besar tidak menyelamatkan dari "Tidur": yang perlu dilakukan
        // terhadapnya adalah menyapanya kembali.
        $this->assertSame('tidur', $this->segmen($tidur));
    }

    public function test_batas_segmen_bisa_diubah_lewat_settings(): void
    {
        $c = $this->pelanggan('Budi');
        $this->pesanan($c, 90);
        $this->assertSame('mau_hilang', $this->segmen($c));

        Setting::write('crm_hari_mau_hilang', '100');

        $this->assertSame('baru', $this->segmen($c));
    }

    public function test_daftar_bisa_disaring_per_segmen_dan_dihitung(): void
    {
        $tidur = $this->pelanggan('Tidur');
        $this->pesanan($tidur, 200);
        $baru = $this->pelanggan('Baru', '81299990000');
        $this->pesanan($baru, 1);

        $ids = collect($this->getJson('/api/customers?segment=tidur')->assertStatus(200)->json('data'))->pluck('id');
        $this->assertEquals([$tidur->id], $ids->all());

        $jumlah = collect($this->getJson('/api/customers/segments')->assertStatus(200)->json('data'))
            ->pluck('count', 'key');
        $this->assertSame(1, $jumlah['tidur']);
        $this->assertSame(1, $jumlah['baru']);
        $this->assertSame(0, $jumlah['vip']);
    }

    public function test_urut_berdasarkan_total_belanja(): void
    {
        $kecil = $this->pelanggan('Kecil');
        $this->pesanan($kecil, 1, 50000);
        $besar = $this->pelanggan('Besar', '81299990000');
        $this->pesanan($besar, 1, 900000);

        $ids = collect($this->getJson('/api/customers?sort=total_spent&order=desc')->assertStatus(200)->json('data'))
            ->pluck('id');
        $this->assertSame([$besar->id, $kecil->id], $ids->take(2)->all());
    }

    /**
     * Ringkasan dan riwayat dihitung dari semua cabang, dan piutang memakai rumus
     * dashboard: pesanan Proses/Siap Diambil yang belum lunas.
     */
    public function test_profil_meringkas_pesanan_semua_cabang(): void
    {
        $c = $this->pelanggan('Budi');
        $lunas = $this->pesanan($c, 10, 200000);
        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $lunas->id, 'date' => time(), 'nominal' => 200000,
        ]);
        $this->pesanan($c, 5, 300000, cabang: 2);
        $this->pesanan($c, 400, 500000, status: 3); // selesai: tidak dihitung piutang

        $data = $this->getJson("/api/customers/{$c->id}/profile")->assertStatus(200)->json('data');

        $this->assertSame(3, $data['summary']['orders_count']);
        $this->assertSame(1000000, $data['summary']['total_spent']);
        $this->assertSame(300000, $data['summary']['receivable']);
        $this->assertCount(3, $data['orders']);
        $this->assertContains('Cabang Malang', array_column($data['orders'], 'branch_name'));
        $this->assertCount(1, $data['payments']);
    }

    public function test_dashboard_meringkas_pelanggan_dan_segmennya(): void
    {
        $baru = $this->pelanggan('Baru');
        $this->pesanan($baru, 0, 100000);
        $kembali = $this->pelanggan('Kembali', '81299990001');
        $this->pesanan($kembali, 400, 300000);
        $this->pesanan($kembali, 0, 300000);
        $hilang = $this->pelanggan('Hilang', '81299990002');
        $this->pesanan($hilang, 90, 5000000);
        $this->pelanggan('Belum', '81299990003');

        $d = $this->getJson('/api/customers/dashboard')->assertStatus(200)->json('data');

        $this->assertSame(4, $d['summary']['total']);
        $this->assertSame(3, $d['summary']['ordered']);
        $this->assertSame(2, $d['summary']['active']);
        $this->assertEquals(33.3, $d['summary']['repeat_rate']);
        $this->assertSame(1, collect($d['segments'])->firstWhere('key', 'mau_hilang')['count']);
        $this->assertSame(5000000, collect($d['segments'])->firstWhere('key', 'mau_hilang')['spent']);
        $this->assertSame($hilang->id, $d['top_customers'][0]['id']);
        $this->assertSame([$hilang->id], array_column($d['at_risk'], 'id'));

        // Bulan ini: satu pelanggan baru, satu yang kembali (order pertamanya 400 hari lalu).
        $bulanIni = collect($d['monthly'])->firstWhere('month', date('Y-m'));
        $this->assertSame(1, $bulanIni['new']);
        $this->assertSame(1, $bulanIni['returning']);
        $this->assertCount(12, $d['monthly']);

        // Batas yang dipakai rumus ikut dikirim untuk teks penjelasan.
        $this->assertSame(
            ['days_at_risk' => 60, 'days_dormant' => 120, 'min_spent_vip' => 2000000, 'min_orders_loyal' => 5],
            $d['rules'],
        );
    }

    /** Bawaan Laravel memotong timestamp jadi tahunnya saja (`2024`) di JSON. */
    public function test_waktu_daftar_dikirim_sebagai_detik_unix(): void
    {
        $c = $this->pelanggan('Budi');
        Customer::whereKey($c->id)->update(['created_at' => 1713589077]);

        $this->getJson("/api/customers/{$c->id}/profile")
            ->assertStatus(200)
            ->assertJsonPath('data.customer.created_at', 1713589077);
        $this->assertSame(1713589077, $this->getJson('/api/customers')->json('data.0.created_at'));
    }

    public function test_profil_menandai_nomor_yang_dipakai_pelanggan_lain(): void
    {
        $a = $this->pelanggan('Budi');
        $b = $this->pelanggan('Budi Juga');
        $this->pelanggan('Lain', '81299990000');

        $dup = $this->getJson("/api/customers/{$a->id}/profile")->assertStatus(200)->json('data.duplicates');

        $this->assertSame([$b->id], array_column($dup, 'id'));
    }

    public function test_catatan_ditulis_dan_dihapus(): void
    {
        $c = $this->pelanggan('Budi');

        $id = $this->postJson("/api/customers/{$c->id}/notes", ['note' => '  Suka sepatu kulit  '])
            ->assertStatus(201)
            ->json('data.id');

        $catatan = $this->getJson("/api/customers/{$c->id}/profile")->json('data.notes');
        $this->assertSame('Suka sepatu kulit', $catatan[0]['note']);

        $this->deleteJson("/api/customers/{$c->id}/notes/{$id}")->assertStatus(200);
        $this->assertSame([], $this->getJson("/api/customers/{$c->id}/profile")->json('data.notes'));
    }

    /** Catatan pelanggan lain tidak bisa dihapus lewat alamat pelanggan ini. */
    public function test_catatan_hanya_bisa_dihapus_lewat_pelanggannya(): void
    {
        $a = $this->pelanggan('Budi');
        $b = $this->pelanggan('Ani', '81299990000');
        $id = $this->postJson("/api/customers/{$a->id}/notes", ['note' => 'x'])->json('data.id');

        $this->deleteJson("/api/customers/{$b->id}/notes/{$id}")->assertStatus(404);
    }

    public function test_tag_dirapikan_dan_bisa_jadi_saringan(): void
    {
        $c = $this->pelanggan('Budi');
        $this->pelanggan('Ani', '81299990000');

        $this->putJson("/api/customers/{$c->id}/tags", ['tags' => ['Reseller', '  reseller ', 'Sering  Komplain', '']])
            ->assertStatus(200)
            ->assertExactJson(['data' => ['reseller', 'sering komplain']]);

        $ids = collect($this->getJson('/api/customers?tag=Reseller')->json('data'))->pluck('id');
        $this->assertEquals([$c->id], $ids->all());

        $this->putJson("/api/customers/{$c->id}/tags", ['tags' => ['sering komplain']])->assertStatus(200);
        $this->assertSame(
            [['tag' => 'sering komplain', 'count' => 1]],
            $this->getJson('/api/customers/tags')->json('data'),
        );
    }

    /** Sosmed boleh membaca profil, tapi catatan dan tag diisi tim yang mengurus pelanggan. */
    public function test_sosmed_membaca_tapi_tidak_menulis(): void
    {
        $c = $this->pelanggan('Budi');
        $this->masukSebagai('Admin Sosmed');

        $this->getJson("/api/customers/{$c->id}/profile")->assertStatus(200);
        $this->postJson("/api/customers/{$c->id}/notes", ['note' => 'x'])->assertStatus(403);
        $this->putJson("/api/customers/{$c->id}/tags", ['tags' => ['a']])->assertStatus(403);

        $this->masukSebagai('Admin Crm');
        $this->postJson("/api/customers/{$c->id}/notes", ['note' => 'x'])->assertStatus(201);
    }
}
