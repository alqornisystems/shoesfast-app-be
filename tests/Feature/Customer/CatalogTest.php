<?php

namespace Tests\Feature\Customer;

use App\Models\Service;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
    }

    public function test_catalog_is_readable_without_a_token(): void
    {
        Service::create([
            'name' => 'Deep Clean',
            'price' => 75000,
            'hpp' => 20000,
            'estimation' => 3,
            'description' => 'Cuci menyeluruh',
        ]);

        $body = $this->getJson('/api/customer/services')->assertStatus(200)->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('Deep Clean', $body['data'][0]['name']);
        $this->assertSame(75000, $body['data'][0]['price']);
        $this->assertSame(3, $body['data'][0]['estimation']);
    }

    public function test_catalog_never_exposes_cost_price(): void
    {
        Service::create(['name' => 'Deep Clean', 'price' => 75000, 'hpp' => 20000]);

        $body = $this->getJson('/api/customer/services')->assertStatus(200)->json();

        $this->assertArrayNotHasKey('hpp', $body['data'][0]);
    }

    public function test_deleted_services_are_hidden(): void
    {
        Service::create(['name' => 'Deep Clean', 'price' => 75000]);
        Service::create(['name' => 'Sudah Dihapus', 'price' => 50000, 'is_deleted' => 1]);

        $body = $this->getJson('/api/customer/services')->assertStatus(200)->json();

        $this->assertCount(1, $body['data']);
    }
}
