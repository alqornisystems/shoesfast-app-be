<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Guarantee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Send;
use App\Models\Treatment;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuaranteeClaimTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
    }

    private function customer(): Customer
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
        ]);

        Sanctum::actingAs($customer, ['*'], 'customer');

        return $customer;
    }

    private function orderWithItem(Customer $customer): array
    {
        $order = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $customer->id,
            'code' => 'INV2026070001', 'date' => time(),
            'total_price' => 195000, 'status' => 3,
        ]);

        $item = OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'name' => 'Nike Air Force 1', 'price' => 195000, 'type' => 2,
        ]);

        return [$order, $item];
    }

    public function test_claim_is_accepted_inside_the_three_day_window(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => $item->id,
            'users_id' => 1, 'date' => time() - 86400, 'type' => 1, 'status' => 1,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Sol lepas lagi setelah dipakai sehari',
        ])->assertStatus(201);

        $this->assertSame(1, Guarantee::withoutGlobalScope('branch')->count());
    }

    public function test_claim_after_three_days_is_rejected_by_the_server(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => $item->id,
            'users_id' => 1, 'date' => time() - (4 * 86400), 'type' => 1, 'status' => 1,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Terlambat',
        ])->assertStatus(422)
            ->assertJson(['message' => 'Masa klaim garansi 3 hari sudah lewat.']);

        $this->assertSame(0, Guarantee::withoutGlobalScope('branch')->count());
    }

    public function test_reference_date_falls_back_to_order_level_delivery(): void
    {
        // 783 baris sends type 1 tidak punya orders_items_id.
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => null,
            'users_id' => 1, 'date' => time() - 3600, 'type' => 1, 'status' => 1,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Warna luntur',
        ])->assertStatus(201);
    }

    public function test_reference_date_falls_back_to_treatment_done_at(): void
    {
        // Barang diambil sendiri di toko: tidak ada baris sends sama sekali.
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Treatment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_items_id' => $item->id, 'services_id' => 1,
            'status' => 2, 'done_at' => time() - 7200, 'price' => 75000,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Jahitan lepas',
        ])->assertStatus(201);
    }

    public function test_item_without_any_reference_date_cannot_be_claimed(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Coba klaim',
        ])->assertStatus(422)
            ->assertJson(['message' => 'Barang ini belum punya tanggal terima. Hubungi toko.']);
    }

    public function test_second_claim_while_first_is_pending_is_rejected(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => $item->id,
            'users_id' => 1, 'date' => time() - 3600, 'type' => 1, 'status' => 1,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", ['note' => 'Pertama'])
            ->assertStatus(201);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", ['note' => 'Kedua'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Klaim untuk barang ini masih diproses.']);

        $this->assertSame(1, Guarantee::withoutGlobalScope('branch')->count());
    }

    public function test_cannot_claim_an_item_of_another_customer(): void
    {
        $mine = $this->customer();

        $other = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Orang Lain', 'phone' => '81299998888',
        ]);
        [$theirOrder, $theirItem] = $this->orderWithItem($other);

        Sanctum::actingAs($mine, ['*'], 'customer');

        $this->postJson("/api/customer/orders/{$theirOrder->id}/items/{$theirItem->id}/claim", [
            'note' => 'Bukan punyaku',
        ])->assertStatus(404);
    }

    public function test_claim_history_lists_own_claims(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => $item->id,
            'users_id' => 1, 'date' => time() - 3600, 'type' => 1, 'status' => 1,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", ['note' => 'Sol lepas'])
            ->assertStatus(201);

        $body = $this->getJson('/api/customer/claims')->assertStatus(200)->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('Nike Air Force 1', $body['data'][0]['item_name']);
        $this->assertSame('Menunggu ditinjau', $body['data'][0]['status_label']);
    }
}
