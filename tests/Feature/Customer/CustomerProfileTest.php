<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Project;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
    }

    private function actingAsCustomer(array $attributes = []): Customer
    {
        $customer = Customer::create(array_merge([
            'projects_id' => 1,
            'name' => 'Budi',
            'phone' => '81200001111',
        ], $attributes));

        Sanctum::actingAs($customer, ['*'], 'customer');

        return $customer;
    }

    public function test_customer_updates_name_and_address(): void
    {
        $this->actingAsCustomer();

        $this->putJson('/api/customer/profile', [
            'name' => 'Budi Santoso',
            'address' => 'Jl. Melati 10, Malang',
        ])->assertStatus(200)
            ->assertJsonPath('customer.name', 'Budi Santoso')
            ->assertJsonPath('customer.address', 'Jl. Melati 10, Malang');
    }

    public function test_customer_saves_map_point_as_coordinates_and_url(): void
    {
        $this->actingAsCustomer();

        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'latitude' => -7.9553004,
            'longitude' => 112.5873766,
            'maps' => 'https://www.google.co.id/maps/place/@-7.9553004,112.5873766,17z',
        ])->assertStatus(200);

        $fresh = Customer::first();
        $this->assertEqualsWithDelta(-7.9553004, (float) $fresh->latitude, 0.0000001);
        $this->assertEqualsWithDelta(112.5873766, (float) $fresh->longitude, 0.0000001);
        $this->assertStringContainsString('112.5873766', $fresh->maps);
    }

    public function test_customer_cannot_move_itself_to_another_branch(): void
    {
        Project::create(['name' => 'Cabang Surabaya']);
        $this->actingAsCustomer();

        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'projects_id' => 2,
        ])->assertStatus(200);

        $this->assertSame(1, Customer::first()->projects_id);
    }

    public function test_customer_cannot_grant_itself_points_or_membership(): void
    {
        $this->actingAsCustomer();

        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'points' => 99999,
            'is_member' => 1,
        ])->assertStatus(200);

        $fresh = Customer::first();
        $this->assertSame(0, (int) $fresh->points);
        $this->assertSame(0, (int) $fresh->is_member);
    }

    public function test_latitude_out_of_range_is_rejected(): void
    {
        $this->actingAsCustomer();

        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'latitude' => 200,
            'longitude' => 112.5,
        ])->assertStatus(422);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->putJson('/api/customer/profile', ['name' => 'Budi'])->assertStatus(401);
    }
}
