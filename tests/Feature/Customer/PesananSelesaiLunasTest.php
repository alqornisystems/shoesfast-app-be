<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pesanan Selesai dianggap lunas: tidak ditagih lagi dan tampil "Lunas", tanpa
 * membuat pembayaran — uang yang tercatat tetap apa adanya.
 */
class PesananSelesaiLunasTest extends TestCase
{
    use CreatesCustomerSchema;

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

    private function pesanan(int $status, int $dibayar = 0): Order
    {
        $c = Customer::firstOrCreate(['phone' => '81200001111'], ['projects_id' => 1, 'name' => 'Budi']);
        $o = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $c->id, 'code' => 'INV'.uniqid(),
            'date' => time(), 'total_price' => 300000, 'status' => $status,
        ]);
        if ($dibayar > 0) {
            Payment::withoutGlobalScope('branch')->create([
                'projects_id' => 1, 'orders_id' => $o->id, 'date' => time(), 'nominal' => $dibayar,
            ]);
        }

        return $o;
    }

    private function idDaftar(string $status): array
    {
        return collect($this->getJson("/api/payments?per_page=50&status={$status}")->assertStatus(200)->json('data'))
            ->pluck('id')->all();
    }

    public function test_pesanan_selesai_tampil_lunas_tanpa_membuat_pembayaran(): void
    {
        $selesaiBelumBayar = $this->pesanan(3);
        $selesaiCicil = $this->pesanan(3, 100000);
        $prosesBelumBayar = $this->pesanan(1);
        $prosesCicil = $this->pesanan(1, 100000);

        $belum = $this->idDaftar('unpaid,partial');
        $this->assertEqualsCanonicalizing([$prosesBelumBayar->id, $prosesCicil->id], $belum);

        $lunas = $this->idDaftar('paid');
        $this->assertEqualsCanonicalizing([$selesaiBelumBayar->id, $selesaiCicil->id], $lunas);

        $baris = collect($this->getJson('/api/payments?per_page=50')->json('data'))->keyBy('id');
        $this->assertSame('paid', $baris[$selesaiCicil->id]['payment_status']);
        $this->assertSame(0, $baris[$selesaiCicil->id]['credit']);
        $this->assertSame(100000, (int) $baris[$selesaiCicil->id]['total_paid']);
        $this->assertSame('partial', $baris[$prosesCicil->id]['payment_status']);

        // Tidak ada uang yang dicatat untuk "melunasi".
        $this->assertSame(2, Payment::withoutGlobalScope('branch')->count());
    }

    public function test_pesanan_selesai_tidak_ditawarkan_untuk_dibayar(): void
    {
        $selesai = $this->pesanan(3);
        $proses = $this->pesanan(1);

        $ids = collect($this->getJson('/api/payments/unpaid-orders')->assertStatus(200)->json())->pluck('id');

        $this->assertContains($proses->id, $ids);
        $this->assertNotContains($selesai->id, $ids);
    }

    public function test_profil_pelanggan_menampilkan_sisa_nol_untuk_pesanan_selesai(): void
    {
        $selesai = $this->pesanan(3, 100000);
        $proses = $this->pesanan(1, 100000);

        $pesanan = collect($this->getJson("/api/customers/{$selesai->customers_id}/profile")->json('data.orders'))->keyBy('id');

        $this->assertSame(0, $pesanan[$selesai->id]['remaining']);
        $this->assertSame(200000, $pesanan[$proses->id]['remaining']);
    }
}
