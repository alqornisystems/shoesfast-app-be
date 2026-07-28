<?php

namespace Tests\Feature\Customer;

use App\Models\Setting;
use Database\Seeders\PortalSettingSeeder;
use Tests\TestCase;

class PortalSettingTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
    }

    public function test_seeder_creates_the_four_portal_settings(): void
    {
        $this->seed(PortalSettingSeeder::class);

        $this->assertSame('25000', Setting::where('key', 'points_rupiah_per_point')->value('value'));
        $this->assertSame('25', Setting::where('key', 'free_pickup_radius_km')->value('value'));
        $this->assertSame('1', Setting::where('key', 'default_branch_id')->value('value'));
        $this->assertStringContainsString('Malang', Setting::where('key', 'free_pickup_terms')->value('value'));
    }

    public function test_settings_endpoint_never_leaks_waha_credentials(): void
    {
        // Tabel yang sama menyimpan kredensial WAHA. Daftar putih di
        // controller adalah satu-satunya yang menahannya.
        Setting::create(['key' => 'waha_api_key', 'value' => 'rahasia-sekali']);
        Setting::create(['key' => 'waha_base_url', 'value' => 'https://wapi.venturo.id']);
        Setting::create(['key' => 'free_pickup_terms', 'value' => 'Gratis untuk Malang kota']);

        $body = $this->getJson('/api/customer/settings')->assertStatus(200)->json();

        $this->assertSame('Gratis untuk Malang kota', $body['data']['free_pickup_terms']);
        $this->assertArrayNotHasKey('waha_api_key', $body['data']);
        $this->assertArrayNotHasKey('waha_base_url', $body['data']);
    }

    public function test_settings_endpoint_is_readable_without_a_token(): void
    {
        Setting::create(['key' => 'free_pickup_radius_km', 'value' => '25']);

        $this->getJson('/api/customer/settings')
            ->assertStatus(200)
            ->assertJsonPath('data.free_pickup_radius_km', '25');
    }

    public function test_seeder_does_not_overwrite_existing_values(): void
    {
        Setting::create(['key' => 'points_rupiah_per_point', 'value' => '10000']);

        $this->seed(PortalSettingSeeder::class);

        $this->assertSame('10000', Setting::where('key', 'points_rupiah_per_point')->value('value'));
    }
}
