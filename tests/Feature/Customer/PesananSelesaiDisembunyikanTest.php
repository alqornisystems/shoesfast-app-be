<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Project;
use App\Models\Role;
use App\Models\Send;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pesanan Selesai sudah beres: tugas antar/jemputnya tidak tampil di daftar
 * pengiriman aktif, tetapi tetap ada di histori.
 */
class PesananSelesaiDisembunyikanTest extends TestCase
{
    use CreatesCustomerSchema;

    private User $kurir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);

        $this->kurir = User::withoutGlobalScopes()->create([
            'name' => 'Kurir Uji', 'email' => 'kurir@uji.test', 'projects_id' => 1, 'password' => 'x',
        ]);
        $this->kurir->setRelation('role', new Role(['name' => 'Admin']));
        Sanctum::actingAs($this->kurir);
    }

    private function tugas(int $statusPesanan): Send
    {
        $c = Customer::firstOrCreate(['phone' => '81200001111'], ['projects_id' => 1, 'name' => 'Budi']);
        $o = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $c->id, 'code' => 'INV'.uniqid(),
            'date' => time(), 'total_price' => 100000, 'status' => $statusPesanan,
        ]);

        return Send::withoutGlobalScopes()->create([
            'projects_id' => 1, 'users_id' => $this->kurir->id, 'orders_id' => $o->id,
            'type' => 0, 'status' => 0, 'date' => time(),
        ]);
    }

    public function test_tugas_pesanan_selesai_hilang_dari_daftar_aktif(): void
    {
        $aktif = $this->tugas(1);
        $selesai = $this->tugas(3);

        foreach (['/api/sends?per_page=50', '/api/sends/in-progress?page=1&per_page=50'] as $url) {
            $ids = collect($this->getJson($url)->assertStatus(200)->json('data'))->pluck('id');
            $this->assertContains($aktif->id, $ids, $url);
            $this->assertNotContains($selesai->id, $ids, $url);
        }

        $this->assertSame(1, $this->getJson('/api/sends/summary')->assertStatus(200)->json('pending'));
    }
}
