<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    public function test_customer_guard_is_configured_with_customers_provider(): void
    {
        $this->assertSame('sanctum', config('auth.guards.customer.driver'));
        $this->assertSame('customers', config('auth.guards.customer.provider'));
        $this->assertSame(Customer::class, config('auth.providers.customers.model'));
    }

    public function test_staff_sanctum_guard_is_pinned_to_users_provider(): void
    {
        // Tanpa ini, provider null membuat token pelanggan diterima di API staf.
        $this->assertSame('users', config('auth.guards.sanctum.provider'));
    }

    public function test_customer_can_issue_a_token(): void
    {
        Project::create(['name' => 'Cabang Malang']);
        $customer = Customer::create([
            'projects_id' => 1,
            'name' => 'Budi',
            'phone' => '81200001111',
        ]);

        $token = $customer->createToken('portal')->plainTextToken;

        $this->assertNotEmpty($token);
        $this->assertSame(1, $customer->tokens()->count());
    }

    private function createSchema(): void
    {
        foreach (['projects', 'customers', 'personal_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('projects', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->decimal('latitude', 10, 8)->nullable();
            $t->decimal('longitude', 11, 8)->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->text('maps')->nullable();
            $t->decimal('latitude', 10, 8)->nullable();
            $t->decimal('longitude', 11, 8)->nullable();
            $t->string('pin')->nullable();
            $t->integer('pin_created_at')->nullable();
            $t->string('pin_created_ip')->nullable();
            $t->tinyInteger('is_member')->default(0);
            $t->string('member_code')->nullable();
            $t->integer('member_since')->nullable();
            $t->integer('points')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }
}
