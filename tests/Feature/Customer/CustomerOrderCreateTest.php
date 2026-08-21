<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Send;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Treatment;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerOrderCreateTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();

        // Titik cabang sungguhan dari produksi.
        Project::create([
            'name' => 'Shoesfast Pusat',
            'latitude' => -8.00784990, 'longitude' => 112.61261450,
        ]);
        Project::create([
            'name' => 'Shoesfast Surabaya',
            'latitude' => -7.26249830, 'longitude' => 112.66643740,
        ]);
    }

    private function customer(array $attributes = []): Customer
    {
        $customer = Customer::withoutGlobalScope('branch')->create(array_merge([
            'projects_id' => 1,
            'name' => 'Budi',
            'phone' => '81200001111',
            'address' => 'Jl. Melati 10',
        ], $attributes));

        Sanctum::actingAs($customer, ['*'], 'customer');

        return $customer;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'type' => 2,
                'name' => 'Nike Air Force 1',
                'checkbox' => [true, false, false],
                'note' => 'Sol menguning',
            ]],
            'pickup' => ['method' => 'antar_sendiri'],
        ], $overrides);
    }

    public function test_creates_order_with_zero_price_and_portal_source(): void
    {
        $this->customer();

        $body = $this->postJson('/api/customer/orders', $this->payload())
            ->assertStatus(201)->json();

        $order = Order::withoutGlobalScope('branch')->find($body['id']);

        $this->assertSame(0, (int) $order->total_price);
        $this->assertSame(0, (int) $order->status);
        $this->assertSame(1, (int) $order->source);
        $this->assertStringStartsWith('INV', $order->code);
    }

    public function test_client_supplied_price_is_ignored(): void
    {
        $this->customer();

        $payload = $this->payload();
        $payload['items'][0]['price'] = 999000;
        $payload['total_price'] = 999000;

        $body = $this->postJson('/api/customer/orders', $payload)->assertStatus(201)->json();

        $order = Order::withoutGlobalScope('branch')->find($body['id']);
        $item = OrderItem::withoutGlobalScope('branch')->where('orders_id', $order->id)->first();

        $this->assertSame(0, (int) $order->total_price);
        $this->assertSame(0, (int) $item->price);
    }

    public function test_checkbox_is_serialized_in_admin_panel_format(): void
    {
        $this->customer();

        $body = $this->postJson('/api/customer/orders', $this->payload())
            ->assertStatus(201)->json();

        $item = OrderItem::withoutGlobalScope('branch')->where('orders_id', $body['id'])->first();

        $this->assertSame('true, false, false', $item->checkbox);
    }

    public function test_bag_items_serialize_seven_slots(): void
    {
        $this->customer();

        $body = $this->postJson('/api/customer/orders', $this->payload([
            'items' => [[
                'type' => 1,
                'name' => 'Tas Coach',
                'checkbox' => [true, false, false, false, false, false, true],
            ]],
        ]))->assertStatus(201)->json();

        $item = OrderItem::withoutGlobalScope('branch')->where('orders_id', $body['id'])->first();

        $this->assertSame('true, false, false, false, false, false, true', $item->checkbox);
    }

    /**
     * Jenis "Lainnya" tidak punya daftar kelengkapan di admin panel — koper, dompet,
     * dan jam tangan tidak berbagi satu set. Sebelumnya jenis ini diam-diam memakai
     * daftar sepatu, jadi pemilik dompet ditanyai soal kaos kaki dan tiga boolean tak
     * bermakna ikut tersimpan. Produksi menyimpan string kosong untuk jenis ini.
     */
    public function test_other_items_store_empty_checklist(): void
    {
        Sanctum::actingAs($this->customer(), ['*'], 'customer');

        $this->postJson('/api/customer/orders', [
            'items' => [[
                'type' => 0,
                'name' => 'Dompet Fossil',
                'checkbox' => [true, true, true],
            ]],
            'pickup' => ['method' => 'antar_sendiri'],
        ])->assertCreated();

        $this->assertSame('', OrderItem::withoutGlobalScope('branch')->first()->checkbox);
    }

    public function test_selected_services_become_treatments(): void
    {
        $service = Service::create(['name' => 'Deep Clean', 'price' => 50000, 'hpp' => 0, 'estimation' => 5]);
        Sanctum::actingAs($this->customer(), ['*'], 'customer');

        $this->postJson('/api/customer/orders', [
            'items' => [[
                'type' => 2,
                'name' => 'AF1 putih',
                'checkbox' => [true, false, false],
                'services' => [$service->id],
            ]],
            'pickup' => ['method' => 'antar_sendiri'],
        ])->assertCreated();

        $treatment = Treatment::withoutGlobalScope('branch')->first();

        $this->assertNotNull($treatment);
        $this->assertSame($service->id, (int) $treatment->services_id);
        $this->assertSame(50000, (int) $treatment->price);
        $this->assertSame(0, (int) $treatment->status);
    }

    /**
     * Pelanggan memilih LAYANAN, tidak pernah HARGA. Harga selalu dibaca ulang dari
     * tabel services — kalau tidak, siapa pun yang bisa menyunting satu request bisa
     * memesan bag spa seharga nol rupiah.
     */
    public function test_service_price_comes_from_catalog_not_request(): void
    {
        $service = Service::create(['name' => 'Bag Spa', 'price' => 275000, 'hpp' => 0, 'estimation' => 5]);
        Sanctum::actingAs($this->customer(), ['*'], 'customer');

        $this->postJson('/api/customer/orders', [
            'items' => [[
                'type' => 1,
                'name' => 'Tas kulit',
                'services' => [$service->id],
                'price' => 0,
            ]],
            'pickup' => ['method' => 'antar_sendiri'],
        ])->assertCreated();

        $this->assertSame(275000, (int) Treatment::withoutGlobalScope('branch')->first()->price);

        // Total pesanan tetap 0: layanan pilihan pelanggan baru sebuah permintaan.
        // Yang menagih adalah petugas setelah barangnya diperiksa, dan selama total
        // masih 0 portal menandainya "belum ada tagihan" alih-alih menagih angka
        // yang belum tentu jadi angka akhirnya.
        $this->assertSame(0, (int) Order::withoutGlobalScope('branch')->first()->total_price);
    }

    public function test_unknown_service_is_rejected(): void
    {
        Sanctum::actingAs($this->customer(), ['*'], 'customer');

        $this->postJson('/api/customer/orders', [
            'items' => [[
                'type' => 2,
                'name' => 'AF1 putih',
                'services' => [999999],
            ]],
            'pickup' => ['method' => 'antar_sendiri'],
        ])->assertStatus(422);
    }

    public function test_pickup_creates_send_row_and_copies_address(): void
    {
        $this->customer([
            'latitude' => -7.98000000, 'longitude' => 112.63000000,
            'maps' => 'https://www.google.co.id/maps/place/@-7.98,112.63,17z',
        ]);

        $body = $this->postJson('/api/customer/orders', $this->payload([
            'pickup' => ['method' => 'jemput', 'date' => '2026-08-01'],
        ]))->assertStatus(201)->json();

        $order = Order::withoutGlobalScope('branch')->find($body['id']);
        $send = Send::withoutGlobalScope('branch')->where('orders_id', $order->id)->first();

        $this->assertSame('Jl. Melati 10', $order->pickup_address);
        $this->assertStringContainsString('112.63', $order->pickup_maps);
        $this->assertSame(0, (int) $send->type);
        $this->assertSame(0, (int) $send->status);
        $this->assertTrue($body['free_pickup']['eligible']);
        $this->assertSame('dalam_radius', $body['free_pickup']['reason']);
    }

    public function test_pickup_without_map_point_is_rejected(): void
    {
        $this->customer();

        $this->postJson('/api/customer/orders', $this->payload([
            'pickup' => ['method' => 'jemput', 'date' => '2026-08-01'],
        ]))->assertStatus(422)
            ->assertJson(['message' => 'Titik peta belum diisi. Lengkapi alamat di profil dulu.']);
    }

    public function test_far_customer_is_flagged_not_rejected(): void
    {
        // Jakarta: jauh di luar radius 25 km dari Malang.
        $this->customer([
            'latitude' => -6.20000000, 'longitude' => 106.81666600,
            'maps' => 'https://www.google.co.id/maps/place/@-6.2,106.816666,17z',
        ]);

        $body = $this->postJson('/api/customer/orders', $this->payload([
            'pickup' => ['method' => 'jemput', 'date' => '2026-08-01'],
        ]))->assertStatus(201)->json();

        $this->assertFalse($body['free_pickup']['eligible']);
        $this->assertSame('luar_radius', $body['free_pickup']['reason']);
        $this->assertGreaterThan(500, $body['free_pickup']['distance_km']);
    }

    public function test_radius_is_configurable_from_settings(): void
    {
        Setting::create(['key' => 'free_pickup_radius_km', 'value' => '1']);

        $this->customer([
            'latitude' => -7.98000000, 'longitude' => 112.63000000,
            'maps' => 'https://maps.example/x',
        ]);

        $body = $this->postJson('/api/customer/orders', $this->payload([
            'pickup' => ['method' => 'jemput', 'date' => '2026-08-01'],
        ]))->assertStatus(201)->json();

        $this->assertFalse($body['free_pickup']['eligible']);
    }

    public function test_order_must_have_at_least_one_item(): void
    {
        $this->customer();

        $this->postJson('/api/customer/orders', ['items' => [], 'pickup' => ['method' => 'antar_sendiri']])
            ->assertStatus(422);
    }

    public function test_admin_order_detail_exposes_pickup_address(): void
    {
        // Tanpa ini kurir tidak tahu harus menjemput ke mana: pesanan portal
        // menyimpan alamatnya, tapi admin panel tidak pernah menampilkannya.
        $this->customer([
            'latitude' => -7.98000000, 'longitude' => 112.63000000,
            'maps' => 'https://www.google.co.id/maps/place/@-7.98,112.63,17z',
        ]);

        $body = $this->postJson('/api/customer/orders', $this->payload([
            'pickup' => ['method' => 'jemput', 'date' => '2026-08-01'],
        ]))->assertStatus(201)->json();

        $user = new \App\Models\User(['name' => 'Admin', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', new \App\Models\Role(['name' => 'Admin']));
        Sanctum::actingAs($user);

        $detail = $this->getJson('/api/orders/'.$body['id'])->assertStatus(200)->json();

        $this->assertSame('Jl. Melati 10', $detail['pickup_address']);
        $this->assertStringContainsString('112.63', $detail['pickup_maps']);
        $this->assertSame(1, $detail['source']);
    }

    public function test_order_code_increments_within_the_month(): void
    {
        $this->customer();

        $first = $this->postJson('/api/customer/orders', $this->payload())->assertStatus(201)->json();
        $second = $this->postJson('/api/customer/orders', $this->payload())->assertStatus(201)->json();

        $this->assertNotSame($first['code'], $second['code']);
        $this->assertSame(
            (int) substr($first['code'], -4) + 1,
            (int) substr($second['code'], -4)
        );
    }
}
