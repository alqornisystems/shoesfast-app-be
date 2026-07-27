<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceShareLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacySchema();
    }

    public function test_order_stores_invoice_token_and_expiry(): void
    {
        $order = $this->seedInvoiceFixture();

        $fresh = Order::withoutBranchScope()->find($order->id);

        $this->assertSame(str_repeat('a', 40), $fresh->invoice_token);
        $this->assertIsInt($fresh->invoice_expires_at);
    }

    public function test_frontend_url_config_is_a_single_origin(): void
    {
        $url = config('app.frontend_url');

        $this->assertIsString($url);
        $this->assertNotSame('', $url);
        $this->assertStringNotContainsString(',', $url);
        $this->assertSame(trim(explode(',', (string) env('FRONTEND_URL', ''))[0]), $url);
    }

    public function test_invoice_link_endpoint_creates_token_and_url(): void
    {
        $order = $this->seedInvoiceFixture();
        $order->update(['invoice_token' => null, 'invoice_expires_at' => null]);

        Sanctum::actingAs($this->branchAdmin());

        $body = $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(200)
            ->json();

        $token = Order::withoutBranchScope()->find($order->id)->invoice_token;

        $this->assertSame(40, strlen($token));
        $this->assertSame(rtrim(config('app.frontend_url'), '/').'/invoice/'.$token, $body['url']);
        $this->assertEqualsWithDelta(time() + 30 * 86400, $body['expires_at'], 5);
    }

    public function test_invoice_link_keeps_token_and_refreshes_expiry(): void
    {
        $order = $this->seedInvoiceFixture();

        Sanctum::actingAs($this->branchAdmin());

        $first = $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(200)
            ->json();

        // Stale the expiry so a refresh is provable.
        Order::withoutBranchScope()->where('id', $order->id)->update(['invoice_expires_at' => 111]);

        $second = $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(200)
            ->json();

        $this->assertSame($first['url'], $second['url']);
        $this->assertSame(
            $first['url'],
            rtrim(config('app.frontend_url'), '/').'/invoice/'.Order::withoutBranchScope()->find($order->id)->invoice_token
        );
        $this->assertGreaterThan(111, $second['expires_at']);
        $this->assertEqualsWithDelta(time() + 30 * 86400, $second['expires_at'], 5);
    }

    public function test_invoice_link_fails_loudly_when_frontend_url_missing(): void
    {
        $order = $this->seedInvoiceFixture();
        $order->update(['invoice_token' => null, 'invoice_expires_at' => null]);

        config(['app.frontend_url' => '']);

        Sanctum::actingAs($this->branchAdmin());

        $this->postJson('/api/orders/'.$order->id.'/invoice-link')
            ->assertStatus(500)
            ->assertJson(['message' => 'FRONTEND_URL belum dikonfigurasi, tautan invoice tidak bisa dibuat']);

        // Nothing was written: a half-made link is worse than no link.
        $this->assertNull(Order::withoutBranchScope()->find($order->id)->invoice_token);
    }

    public function test_public_invoice_returns_invoice_header(): void
    {
        $order = $this->seedInvoiceFixture();

        $this->getJson('/api/public/invoice/'.$order->invoice_token)
            ->assertStatus(200)
            ->assertJsonPath('code', 'INV-2026-001')
            ->assertJsonPath('date', strtotime('2026-03-01 09:00:00'))
            ->assertJsonPath('branch.name', 'Cabang Kemang')
            ->assertJsonPath('branch.whatsapp', '081277001100')
            ->assertJsonPath('customer.name', 'Budi Santoso')
            ->assertJsonPath('customer.phone', '081200001111')
            ->assertJsonPath('customer.email', null)
            ->assertJsonPath('customer.address', 'Jl. Melati 10');
    }

    public function test_public_invoice_returns_404_for_unknown_token(): void
    {
        $this->seedInvoiceFixture();

        $this->getJson('/api/public/invoice/'.str_repeat('z', 40))
            ->assertStatus(404)
            ->assertJson(['message' => 'Invoice tidak ditemukan']);
    }

    private function branchAdmin(int $projectId = 1): User
    {
        $user = new User(['name' => 'Admin Kemang', 'projects_id' => $projectId]);
        $user->id = 1;

        return $user;
    }

    /**
     * The legacy tables have no migrations (production is the source of truth),
     * so the sqlite :memory: test DB is built by hand here.
     */
    private function createLegacySchema(): void
    {
        foreach (['projects', 'customers', 'orders', 'orders_items', 'services', 'treatments', 'payments'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('projects', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('whatsapp')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('customers_id')->nullable();
            $t->string('code')->nullable();
            $t->integer('date')->nullable();
            $t->integer('total_discount')->default(0);
            $t->integer('total_price')->default(0);
            $t->text('note')->nullable();
            $t->string('invoice_token', 40)->nullable()->unique();
            $t->integer('invoice_expires_at')->nullable();
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('orders_items', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('orders_id')->nullable();
            $t->integer('services_id')->nullable();
            $t->text('photo')->nullable();
            $t->string('name')->nullable();
            $t->integer('price')->default(0);
            $t->integer('discount')->default(0);
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('type')->default(0);
            $t->text('checkbox')->nullable();
            $t->text('note')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('services', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('price')->default(0);
            $t->integer('hpp')->nullable();
            $t->integer('estimation')->nullable();
            $t->text('photo')->nullable();
            $t->text('description')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('treatments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('orders_items_id')->nullable();
            $t->integer('services_id')->nullable();
            $t->tinyInteger('status')->default(0);
            $t->integer('price')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('orders_id')->nullable();
            $t->integer('date')->nullable();
            $t->integer('nominal')->default(0);
            $t->text('note')->nullable();
            $t->text('photo')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });
    }

    /**
     * One branch, one customer, one order (195.000) with a single item that has
     * two priced treatments, plus a partial payment of 150.000.
     */
    private function seedInvoiceFixture(): Order
    {
        Project::create([
            'name' => 'Cabang Kemang',
            'phone' => '02177001100',
            'whatsapp' => '081277001100',
        ]);

        Customer::create([
            'projects_id' => 1,
            'name' => 'Budi Santoso',
            'phone' => '081200001111',
            'email' => null,
            'address' => 'Jl. Melati 10',
        ]);

        $order = Order::create([
            'projects_id' => 1,
            'customers_id' => 1,
            'code' => 'INV-2026-001',
            'date' => strtotime('2026-03-01 09:00:00'),
            'total_discount' => 0,
            'total_price' => 195000,
            'note' => null,
            'status' => 1,
            'invoice_token' => str_repeat('a', 40),
            'invoice_expires_at' => time() + 86400,
        ]);

        Service::create(['name' => 'Deep Clean', 'price' => 75000]);
        Service::create(['name' => 'Unyellowing', 'price' => 120000]);

        OrderItem::create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'services_id' => 1,
            'photo' => 'items/item-12-1.jpg',
            'name' => 'Nike Air Force 1',
            'price' => 195000,
            'discount' => 0,
            'status' => 1,
            'type' => 0,
        ]);

        Treatment::create([
            'projects_id' => 1,
            'orders_items_id' => 1,
            'services_id' => 1,
            'status' => 3,
            'price' => 75000,
        ]);

        Treatment::create([
            'projects_id' => 1,
            'orders_items_id' => 1,
            'services_id' => 2,
            'status' => 3,
            'price' => 120000,
        ]);

        Payment::create([
            'projects_id' => 1,
            'orders_id' => $order->id,
            'date' => strtotime('2026-03-02 10:00:00'),
            'nominal' => 150000,
            'note' => 'Transfer BCA',
        ]);

        return $order;
    }
}
