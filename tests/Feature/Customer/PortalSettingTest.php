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

    public function test_seeder_does_not_overwrite_existing_values(): void
    {
        Setting::create(['key' => 'points_rupiah_per_point', 'value' => '10000']);

        $this->seed(PortalSettingSeeder::class);

        $this->assertSame('10000', Setting::where('key', 'points_rupiah_per_point')->value('value'));
    }
}
