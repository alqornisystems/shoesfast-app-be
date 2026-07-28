<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Guarantee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminClaimTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
    }

    private function adminAktif(string $roleName = 'Admin'): void
    {
        $user = new User(['name' => 'Admin', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => $roleName]));

        Sanctum::actingAs($user);
    }

    private function penukaran(): RewardRedemption
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'is_member' => 1, 'points' => 10,
        ]);

        $reward = Reward::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Cuci Gratis', 'type' => 0,
            'points_cost' => 50, 'is_active' => 1,
        ]);

        return RewardRedemption::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $customer->id,
            'rewards_id' => $reward->id, 'code' => 'TKRABCD1234',
            'points_spent' => 50, 'status' => 0, 'date' => time(),
        ]);
    }

    private function klaim(): Guarantee
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
        ]);

        $order = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $customer->id,
            'code' => 'INV2026070001', 'date' => time(),
            'total_price' => 195000, 'status' => 3,
        ]);

        $item = OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'name' => 'Nike Air Force 1', 'price' => 195000, 'type' => 2,
        ]);

        return Guarantee::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_items_id' => $item->id,
            'note' => 'Sol lepas', 'status' => 0,
        ]);
    }

    public function test_redemption_list_shows_code_and_customer(): void
    {
        $this->adminAktif();
        $this->penukaran();

        $body = $this->getJson('/api/redemptions')->assertStatus(200)->json();

        $this->assertSame('TKRABCD1234', $body['data'][0]['code']);
        $this->assertSame('Budi', $body['data'][0]['customer_name']);
        $this->assertSame('Cuci Gratis', $body['data'][0]['reward_name']);
        $this->assertSame('Menunggu diambil', $body['data'][0]['status_label']);
    }

    public function test_redemption_can_be_searched_by_code(): void
    {
        // Admin mencari dengan kode yang ditunjukkan pelanggan di konter.
        $this->adminAktif();
        $this->penukaran();

        $this->getJson('/api/redemptions?search=ABCD1234')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/redemptions?search=TIDAKADA')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_completing_a_redemption_does_not_refund_points(): void
    {
        $this->adminAktif();
        $r = $this->penukaran();

        $this->postJson('/api/redemptions/'.$r->id.'/complete')->assertStatus(200);

        $this->assertSame(
            1,
            (int) RewardRedemption::withoutGlobalScope('branch')->find($r->id)->status,
        );
        // Poin sudah dipotong saat menukar; menandai selesai tidak menyentuhnya.
        $this->assertSame(
            10,
            (int) Customer::withoutGlobalScope('branch')->first()->points,
        );
    }

    public function test_completing_twice_is_harmless(): void
    {
        $this->adminAktif();
        $r = $this->penukaran();

        $this->postJson('/api/redemptions/'.$r->id.'/complete')->assertStatus(200);
        $this->postJson('/api/redemptions/'.$r->id.'/complete')->assertStatus(200);

        $this->assertSame(
            1,
            (int) RewardRedemption::withoutGlobalScope('branch')->find($r->id)->status,
        );
    }

    public function test_claim_list_shows_item_and_customer(): void
    {
        $this->adminAktif();
        $this->klaim();

        $body = $this->getJson('/api/guarantee-claims')->assertStatus(200)->json();

        $this->assertSame('Nike Air Force 1', $body['data'][0]['item_name']);
        $this->assertSame('Budi', $body['data'][0]['customer_name']);
        $this->assertSame('INV2026070001', $body['data'][0]['order_code']);
        $this->assertSame('Menunggu ditinjau', $body['data'][0]['status_label']);
    }

    public function test_claim_list_survives_a_deleted_item(): void
    {
        // Barang, pesanan, dan pelanggan semuanya bisa terhapus setelah klaim
        // dibuat; satu baris yatim tidak boleh menjatuhkan seluruh daftar.
        $this->adminAktif();
        Guarantee::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_items_id' => 999,
            'note' => 'Barangnya sudah hilang dari sistem', 'status' => 0,
        ]);

        $body = $this->getJson('/api/guarantee-claims')->assertStatus(200)->json();

        $this->assertNull($body['data'][0]['item_name']);
        $this->assertNull($body['data'][0]['order_code']);
        $this->assertNull($body['data'][0]['customer_name']);
    }

    public function test_claim_can_be_approved_with_a_cost(): void
    {
        $this->adminAktif();
        $c = $this->klaim();

        $this->putJson('/api/guarantee-claims/'.$c->id, [
            'status' => 1,
            'price' => 25000,
            'note' => 'Sol dilem ulang, gratis',
        ])->assertStatus(200);

        $fresh = Guarantee::withoutGlobalScope('branch')->find($c->id);
        $this->assertSame(1, (int) $fresh->status);
        $this->assertSame(25000, (int) $fresh->price);
    }

    public function test_claim_status_must_be_approved_or_rejected(): void
    {
        $this->adminAktif();
        $c = $this->klaim();

        $this->putJson('/api/guarantee-claims/'.$c->id, ['status' => 9])
            ->assertStatus(422);

        // 0 juga ditolak: mengembalikan klaim ke "menunggu" setelah pelanggan
        // diberi tahu keputusannya hanya membingungkan.
        $this->putJson('/api/guarantee-claims/'.$c->id, ['status' => 0])
            ->assertStatus(422);
    }

    public function test_technician_cannot_review_claims(): void
    {
        $this->adminAktif('Teknisi');
        $c = $this->klaim();

        $this->getJson('/api/guarantee-claims')->assertStatus(403);
        $this->putJson('/api/guarantee-claims/'.$c->id, ['status' => 1])->assertStatus(403);
    }
}
