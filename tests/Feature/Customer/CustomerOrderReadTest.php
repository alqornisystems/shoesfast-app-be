<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Treatment;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerOrderReadTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();

        Project::create(['name' => 'Cabang Malang']);
        Project::create(['name' => 'Cabang Surabaya']);
        Service::create(['name' => 'Deep Clean', 'price' => 75000, 'estimation' => 3]);
    }

    private function customer(int $branch = 1, string $phone = '81200001111'): Customer
    {
        return Customer::withoutGlobalScope('branch')->create([
            'projects_id' => $branch,
            'name' => 'Budi',
            'phone' => $phone,
        ]);
    }

    private function order(Customer $customer, array $attributes = []): Order
    {
        return Order::withoutGlobalScope('branch')->create(array_merge([
            'projects_id' => $customer->projects_id,
            'customers_id' => $customer->id,
            'code' => 'INV2026070001',
            'date' => strtotime('2026-07-01 09:00:00'),
            'total_price' => 195000,
            'status' => 1,
        ], $attributes));
    }

    public function test_history_lists_only_own_orders(): void
    {
        $me = $this->customer();
        $other = $this->customer(1, '81299998888');

        $this->order($me);
        $this->order($other, ['code' => 'INV2026070002']);

        Sanctum::actingAs($me, ['*'], 'customer');

        $body = $this->getJson('/api/customer/orders')->assertStatus(200)->json();

        $this->assertSame(1, $body['total']);
        $this->assertSame('INV2026070001', $body['data'][0]['code']);
    }

    public function test_detail_of_another_customers_order_returns_404(): void
    {
        $me = $this->customer();
        $other = $this->customer(1, '81299998888');
        $theirOrder = $this->order($other, ['code' => 'INV2026070002']);

        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/orders/'.$theirOrder->id)->assertStatus(404);
    }

    public function test_detail_of_another_branch_order_returns_404(): void
    {
        // Pemeriksaan ganda: pemilik DAN cabang. Kalau salah satu dilepas,
        // tes ini yang menangkapnya.
        $me = $this->customer(1);
        $orderInOtherBranch = $this->order($me, [
            'projects_id' => 2,
            'code' => 'INV2026070003',
        ]);

        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/orders/'.$orderInOtherBranch->id)->assertStatus(404);
    }

    public function test_status_labels_never_say_dibatalkan(): void
    {
        // Admin panel memberi label 3 = "Dibatalkan", padahal 2.448 pesanan
        // berstatus 3 lunas dibayar. Portal tidak boleh menyalin itu.
        $me = $this->customer();
        $order = $this->order($me, ['status' => 3]);

        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/orders/'.$order->id)
            ->assertStatus(200)
            ->assertJsonPath('status_label', 'Selesai');
    }

    public function test_detail_reports_money_and_timeline(): void
    {
        $me = $this->customer();
        $order = $this->order($me);

        $item = OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'name' => 'Nike Air Force 1',
            'price' => 195000,
            'type' => 2,
            'checkbox' => 'true, false, false',
        ]);

        Treatment::withoutGlobalScope('branch')->create([
            'projects_id' => 1,
            'orders_items_id' => $item->id,
            'services_id' => 1,
            'status' => 2,
            'done_at' => strtotime('2026-07-03 15:00:00'),
            'price' => 75000,
        ]);

        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'date' => strtotime('2026-07-02 10:00:00'),
            'nominal' => 150000,
        ]);

        Sanctum::actingAs($me, ['*'], 'customer');

        $body = $this->getJson('/api/customer/orders/'.$order->id)->assertStatus(200)->json();

        $this->assertSame(195000, $body['total_price']);
        $this->assertSame(150000, $body['total_paid']);
        $this->assertSame(45000, $body['credit']);
        $this->assertSame('Nike Air Force 1', $body['items'][0]['name']);
        $this->assertSame(['Tali Sepatu'], $body['items'][0]['kelengkapan']);
        $this->assertSame('Deep Clean', $body['items'][0]['treatments'][0]['name']);
        $this->assertNotEmpty($body['timeline']);
    }

    public function test_bag_items_use_the_seven_slot_checklist(): void
    {
        // Tas punya 7 kelengkapan, sepatu 3. Daftarnya harus cermin
        // order-form-client.tsx di admin panel.
        $me = $this->customer();
        $order = $this->order($me);

        OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'name' => 'Tas Coach',
            'price' => 250000,
            'type' => 1,
            'checkbox' => 'true, false, false, false, false, false, true',
        ]);

        Sanctum::actingAs($me, ['*'], 'customer');

        $body = $this->getJson('/api/customer/orders/'.$order->id)->assertStatus(200)->json();

        $this->assertSame(['Dust Bag', 'Receipt'], $body['items'][0]['kelengkapan']);
    }

    public function test_invoice_matches_the_public_invoice_arithmetic(): void
    {
        $me = $this->customer();
        $order = $this->order($me);

        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'date' => strtotime('2026-07-02 10:00:00'), 'nominal' => 150000,
            'note' => 'Transfer BCA',
        ]);

        Sanctum::actingAs($me, ['*'], 'customer');

        $body = $this->getJson('/api/customer/orders/'.$order->id.'/invoice')
            ->assertStatus(200)->json();

        // Aturan sama dengan PaymentController::index dan PublicInvoiceController:
        // jatuh tempo 3 hari setelah tanggal pesanan.
        $this->assertSame(strtotime('2026-07-04 00:00:00'), $body['due_date']);
        $this->assertSame(195000, $body['total_price']);
        $this->assertSame(150000, $body['total_paid']);
        $this->assertSame(45000, $body['credit']);
        $this->assertSame('partial', $body['payment_status']);
        $this->assertSame('Transfer BCA', $body['payments'][0]['note']);
    }

    public function test_invoice_of_another_customer_returns_404(): void
    {
        $me = $this->customer();
        $other = $this->customer(1, '81299998888');
        $theirOrder = $this->order($other, ['code' => 'INV2026070009']);

        Sanctum::actingAs($me, ['*'], 'customer');

        $this->getJson('/api/customer/orders/'.$theirOrder->id.'/invoice')->assertStatus(404);
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson('/api/customer/orders')->assertStatus(401);
    }
}
