<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Daftar pesanan menyembunyikan pesanan Selesai secara bawaan, dan admin bisa
 * menandai banyak pesanan Selesai sekaligus.
 */
class PesananSelesaiTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
        Project::create(['name' => 'Cabang Surabaya']);

        $admin = new User(['name' => 'Admin Uji', 'projects_id' => 1]);
        $admin->id = 1;
        $admin->setRelation('role', new Role(['name' => 'Admin']));
        Sanctum::actingAs($admin);
    }

    private function pesanan(int $status, int $cabang = 1): Order
    {
        $c = Customer::firstOrCreate(['phone' => '81200001111'], ['projects_id' => 1, 'name' => 'Budi']);

        return Order::withoutGlobalScope('branch')->create([
            'projects_id' => $cabang, 'customers_id' => $c->id,
            'code' => 'INV'.uniqid(), 'date' => time(), 'total_price' => 100000, 'status' => $status,
        ]);
    }

    private function statusPesanan(Order $o): int
    {
        return (int) Order::withoutGlobalScope('branch')->find($o->id)->status;
    }

    public function test_saringan_belum_selesai_melewatkan_pesanan_selesai(): void
    {
        $belum = collect([0, 1, 2])->map(fn ($s) => $this->pesanan($s)->id);
        $selesai = $this->pesanan(3);

        $ids = collect($this->getJson('/api/orders?status=belum_selesai&per_page=50')->assertStatus(200)->json('data'))
            ->pluck('id');

        $this->assertEqualsCanonicalizing($belum->all(), $ids->all());
        $this->assertNotContains($selesai->id, $ids);
        // Tanpa saringan: semua tetap bisa dilihat.
        $this->assertCount(4, $this->getJson('/api/orders?per_page=50')->json('data'));
    }

    public function test_tandai_selesai_sekaligus(): void
    {
        $a = $this->pesanan(1);
        $b = $this->pesanan(2);
        $sudah = $this->pesanan(3);

        $this->postJson('/api/orders/mark-completed', ['ids' => [$a->id, $b->id, $sudah->id]])
            ->assertStatus(200)
            ->assertJsonPath('updated', 2);

        $this->assertSame(3, $this->statusPesanan($a));
        $this->assertSame(3, $this->statusPesanan($b));
    }

    /** Id pesanan cabang lain ikut terkirim: saringan cabang yang menahannya. */
    public function test_pesanan_cabang_lain_tidak_tersentuh(): void
    {
        $lain = $this->pesanan(1, cabang: 2);

        $this->postJson('/api/orders/mark-completed', ['ids' => [$lain->id]])
            ->assertStatus(200)
            ->assertJsonPath('updated', 0);

        $this->assertSame(1, $this->statusPesanan($lain));
    }

    public function test_daftar_id_wajib_diisi(): void
    {
        $this->postJson('/api/orders/mark-completed', ['ids' => []])->assertStatus(422);
    }
}
