<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Services\CustomerPointService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MembershipPointTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::withoutGlobalScope('branch')->create(array_merge([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
        ], $attributes));
    }

    private function order(Customer $customer, int $totalPrice): Order
    {
        return Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1,
            'customers_id' => $customer->id,
            'code' => 'INV2026070001',
            'date' => time(),
            'total_price' => $totalPrice,
            'status' => 1,
        ]);
    }

    private function pay(Order $order, int $nominal): void
    {
        Payment::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'date' => time(), 'nominal' => $nominal,
        ]);
    }

    public function test_join_marks_customer_as_member_with_code(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer, ['*'], 'customer');

        $this->postJson('/api/customer/membership/join')
            ->assertStatus(200)
            ->assertJsonPath('customer.is_member', 1);

        $fresh = Customer::withoutGlobalScope('branch')->find($customer->id);
        $this->assertNotEmpty($fresh->member_code);
        // Kolomnya DATE. Assertion lama menuntut integer dan justru mengunci
        // bentuk yang ditolak MySQL.
        $this->assertSame(date('Y-m-d'), $fresh->member_since);
    }

    public function test_join_twice_keeps_the_original_code(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($customer, ['*'], 'customer');

        $this->postJson('/api/customer/membership/join')->assertStatus(200);
        $firstCode = Customer::withoutGlobalScope('branch')->find($customer->id)->member_code;

        $this->postJson('/api/customer/membership/join')->assertStatus(200);

        $this->assertSame($firstCode, Customer::withoutGlobalScope('branch')->find($customer->id)->member_code);
    }

    public function test_member_earns_floor_of_price_divided_by_rate(): void
    {
        $customer = $this->customer(['is_member' => 1]);
        $order = $this->order($customer, 349000);
        $this->pay($order, 349000);

        $awarded = app(CustomerPointService::class)->awardIfSettled($order->id);

        // 349.000 / 25.000 = 13,96 -> 13
        $this->assertSame(13, $awarded);
        $this->assertSame(13, (int) Customer::withoutGlobalScope('branch')->find($customer->id)->points);
    }

    public function test_non_member_earns_nothing(): void
    {
        $customer = $this->customer(['is_member' => 0]);
        $order = $this->order($customer, 349000);
        $this->pay($order, 349000);

        $this->assertSame(0, app(CustomerPointService::class)->awardIfSettled($order->id));
        $this->assertSame(0, (int) Customer::withoutGlobalScope('branch')->find($customer->id)->points);
    }

    public function test_partial_payment_earns_nothing(): void
    {
        $customer = $this->customer(['is_member' => 1]);
        $order = $this->order($customer, 349000);
        $this->pay($order, 100000);

        $this->assertSame(0, app(CustomerPointService::class)->awardIfSettled($order->id));
    }

    public function test_points_are_awarded_only_once(): void
    {
        $customer = $this->customer(['is_member' => 1]);
        $order = $this->order($customer, 349000);
        $this->pay($order, 349000);

        $service = app(CustomerPointService::class);
        $this->assertSame(13, $service->awardIfSettled($order->id));
        $this->assertSame(0, $service->awardIfSettled($order->id));
        $this->assertSame(0, $service->awardIfSettled($order->id));

        $this->assertSame(13, (int) Customer::withoutGlobalScope('branch')->find($customer->id)->points);
    }

    public function test_zero_price_order_earns_nothing(): void
    {
        // Pesanan dari portal punya total_price 0 sampai admin mengisi harga.
        $customer = $this->customer(['is_member' => 1]);
        $order = $this->order($customer, 0);
        $this->pay($order, 0);

        $this->assertSame(0, app(CustomerPointService::class)->awardIfSettled($order->id));
    }

    public function test_joining_later_grants_no_retroactive_points(): void
    {
        $customer = $this->customer(['is_member' => 0]);
        $order = $this->order($customer, 349000);
        $this->pay($order, 349000);

        $service = app(CustomerPointService::class);
        $service->awardIfSettled($order->id);

        $customer->update(['is_member' => 1]);
        $service->awardIfSettled($order->id);

        $this->assertSame(0, (int) Customer::withoutGlobalScope('branch')->find($customer->id)->points);
    }

    public function test_rate_is_configurable_from_settings(): void
    {
        Setting::create(['key' => 'points_rupiah_per_point', 'value' => '10000']);

        $customer = $this->customer(['is_member' => 1]);
        $order = $this->order($customer, 349000);
        $this->pay($order, 349000);

        $this->assertSame(34, app(CustomerPointService::class)->awardIfSettled($order->id));
    }

    public function test_membership_endpoint_reports_balance_and_rate(): void
    {
        $customer = $this->customer(['is_member' => 1, 'points' => 42, 'member_code' => 'MBR000001']);
        Sanctum::actingAs($customer, ['*'], 'customer');

        $this->getJson('/api/customer/membership')
            ->assertStatus(200)
            ->assertJson([
                'is_member' => 1,
                'member_code' => 'MBR000001',
                'points' => 42,
                'rupiah_per_point' => 25000,
            ]);
    }
}
