<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Project;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
    }

    public function test_check_phone_reports_unknown_number(): void
    {
        $this->postJson('/api/customer/auth/check-phone', ['phone' => '81200009999'])
            ->assertStatus(200)
            ->assertJson(['exists' => false, 'has_pin' => false]);
    }

    public function test_check_phone_normalizes_all_three_formats(): void
    {
        Customer::create(['projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111']);

        foreach (['081200001111', '+6281200001111', '81200001111'] as $input) {
            $this->postJson('/api/customer/auth/check-phone', ['phone' => $input])
                ->assertStatus(200)
                ->assertJson(['exists' => true, 'has_pin' => false, 'name' => 'Budi']);
        }
    }

    public function test_duplicate_phone_resolves_to_earliest_created(): void
    {
        Customer::create(['projects_id' => 1, 'name' => 'Yang Lama', 'phone' => '81200001111', 'created_at' => 1000]);
        Customer::create(['projects_id' => 1, 'name' => 'Yang Baru', 'phone' => '81200001111', 'created_at' => 2000]);

        $this->postJson('/api/customer/auth/check-phone', ['phone' => '81200001111'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Yang Lama');
    }

    public function test_set_pin_issues_token_and_records_provenance(): void
    {
        Customer::create(['projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111']);

        $body = $this->postJson('/api/customer/auth/set-pin', [
            'phone' => '081200001111',
            'pin' => '123456',
        ])->assertStatus(200)->json();

        $this->assertNotEmpty($body['token']);
        $this->assertSame('Budi', $body['customer']['name']);

        $fresh = Customer::first();
        $this->assertNotSame('123456', $fresh->pin, 'PIN wajib di-hash');
        $this->assertTrue(password_verify('123456', $fresh->pin));
        $this->assertIsInt($fresh->pin_created_at);
        $this->assertNotNull($fresh->pin_created_ip);
    }

    public function test_set_pin_refuses_when_pin_already_exists(): void
    {
        Customer::create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('111111'),
        ]);

        $this->postJson('/api/customer/auth/set-pin', ['phone' => '81200001111', 'pin' => '222222'])
            ->assertStatus(422)
            ->assertJson(['message' => 'PIN sudah pernah dibuat. Gunakan lupa PIN bila lupa.']);
    }

    public function test_login_succeeds_with_correct_pin(): void
    {
        Customer::create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('123456'),
        ]);

        $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '123456'])
            ->assertStatus(200)
            ->assertJsonPath('customer.name', 'Budi')
            ->assertJsonMissingPath('customer.pin');
    }

    public function test_login_rejects_wrong_pin(): void
    {
        Customer::create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('123456'),
        ]);

        $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '999999'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Nomor HP atau PIN salah.']);
    }

    public function test_account_locks_after_five_wrong_pins(): void
    {
        Customer::create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('123456'),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '999999'])
                ->assertStatus(401);
        }

        // Percobaan keenam ditolak 429 walau PIN-nya benar: PIN 6 digit hanya
        // punya sejuta kemungkinan, tanpa rem ini bisa disapu berurutan.
        $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '123456'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.');
    }

    public function test_successful_login_clears_the_attempt_counter(): void
    {
        Customer::create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('123456'),
        ]);

        $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '999999'])
            ->assertStatus(401);

        $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '123456'])
            ->assertStatus(200);

        // Hitungan direset, jadi 4 kegagalan berikutnya belum mengunci.
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '999999'])
                ->assertStatus(401);
        }

        $this->postJson('/api/customer/auth/login', ['phone' => '81200001111', 'pin' => '123456'])
            ->assertStatus(200);
    }

    public function test_register_creates_customer_with_default_branch(): void
    {
        $body = $this->postJson('/api/customer/auth/register', [
            'name' => 'Sinta',
            'phone' => '081299998888',
            'pin' => '654321',
        ])->assertStatus(201)->json();

        $this->assertNotEmpty($body['token']);
        $this->assertSame('81299998888', Customer::where('name', 'Sinta')->first()->phone);
    }

    public function test_register_refuses_existing_phone(): void
    {
        Customer::create(['projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111']);

        $this->postJson('/api/customer/auth/register', [
            'name' => 'Penipu', 'phone' => '81200001111', 'pin' => '654321',
        ])->assertStatus(422);
    }

    public function test_me_requires_a_customer_token(): void
    {
        $this->getJson('/api/customer/auth/me')->assertStatus(401);
    }
}
