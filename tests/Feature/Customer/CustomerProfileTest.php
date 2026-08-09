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

    public function test_customer_uploads_then_removes_photo(): void
    {
        $this->actingAsCustomer();
        $foto = 'data:image/jpeg;base64,'.base64_encode('sebuah-foto');

        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'photo' => $foto,
        ])->assertStatus(200)->assertJsonPath('customer.photo', $foto);

        // photo:null berarti "hapus", bukan "biarkan" — pembacaan pakai ??
        // akan membuat tombol hapus di portal tidak pernah bekerja.
        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'photo' => null,
        ])->assertStatus(200)->assertJsonPath('customer.photo', null);

        $this->assertNull(Customer::first()->photo);
    }

    public function test_photo_larger_than_the_text_column_is_rejected(): void
    {
        $this->actingAsCustomer();

        // Kolomnya TEXT (65.535 byte). Tanpa aturan max, MySQL memotong diam-
        // diam dan yang tersimpan adalah data URL rusak.
        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'photo' => str_repeat('a', 61_000),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_customer_saves_instagram_and_birthday(): void
    {
        $this->actingAsCustomer();

        $this->putJson('/api/customer/profile', [
            'name' => 'Budi',
            'instagram' => 'budi.shoes',
            'date_of_birth' => 852076800,
        ])->assertStatus(200)
            ->assertJsonPath('customer.instagram', 'budi.shoes')
            ->assertJsonPath('customer.date_of_birth', 852076800);
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
