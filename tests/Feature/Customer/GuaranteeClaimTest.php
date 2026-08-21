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

    public function test_claim_is_accepted_inside_the_window(): void
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

    public function test_claim_after_the_window_is_rejected_by_the_server(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => $item->id,
            'users_id' => 1, 'date' => time() - (8 * 86400), 'type' => 1, 'status' => 1,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Terlambat',
        ])->assertStatus(422)
            ->assertJson(['message' => 'Masa klaim garansi 7 hari sudah lewat.']);

        $this->assertSame(0, Guarantee::withoutGlobalScope('branch')->count());
    }

    /** Hari ketujuh masih di dalam, bukan di luar. */
    public function test_the_seventh_day_still_counts(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => $item->id,
            'users_id' => 1, 'date' => time() - (7 * 86400) + 60, 'type' => 1, 'status' => 1,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Masih sempat',
        ])->assertStatus(201);
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

    /** Diambil sendiri di cabang: tidak ada baris sends, jadi acuannya saat pesanan ditutup. */
    public function test_reference_date_falls_back_to_when_the_order_was_closed(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);
        $order->forceFill(['modified_at' => time() - 7200])->saveQuietly();

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Jahitan lepas',
        ])->assertStatus(201);
    }

    /**
     * Tanggal selesai DIKERJAKAN tidak pernah dipakai. Sepatu yang menginap seminggu
     * di rak toko akan kehabisan garansi sebelum pemiliknya memegangnya, dan yang
     * dijanjikan garansi adalah hasil kerja yang dipakai — bukan yang masih di rak.
     */
    public function test_finishing_the_work_does_not_start_the_clock(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);
        $order->forceFill(['status' => 1, 'modified_at' => time()])->saveQuietly();

        Treatment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_items_id' => $item->id, 'services_id' => 1,
            'status' => 2, 'done_at' => time() - 7200, 'price' => 75000,
        ]);

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Coba klaim',
        ])->assertStatus(422)
            ->assertJson(['message' => 'Pesanan ini belum selesai. Klaim dibuka setelah barang kamu terima.']);
    }

    /** Barang masih di bengkel: belum ada yang bisa diklaim. */
    public function test_unfinished_order_cannot_be_claimed(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);
        $order->forceFill(['status' => 1])->saveQuietly();

        $this->postJson("/api/customer/orders/{$order->id}/items/{$item->id}/claim", [
            'note' => 'Coba klaim',
        ])->assertStatus(422);

        $this->assertSame(0, Guarantee::withoutGlobalScope('branch')->count());
    }

    /** Portal memutuskan tombolnya dari sini, jadi jawabannya harus sama dengan endpoint klaim. */
    public function test_order_detail_reports_claim_eligibility_per_item(): void
    {
        $customer = $this->customer();
        [$order, $item] = $this->orderWithItem($customer);

        Send::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'orders_items_id' => $item->id,
            'users_id' => 1, 'date' => time() - 86400, 'type' => 1, 'status' => 1,
        ]);

        $klaim = $this->getJson("/api/customer/orders/{$order->id}")
            ->assertOk()->json('items.0.claim');

        $this->assertTrue($klaim['eligible']);
        $this->assertNull($klaim['reason']);

        $order->forceFill(['status' => 1])->saveQuietly();

        $klaim = $this->getJson("/api/customer/orders/{$order->id}")
            ->assertOk()->json('items.0.claim');

        $this->assertFalse($klaim['eligible']);
        $this->assertSame('belum_selesai', $klaim['reason']);
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
