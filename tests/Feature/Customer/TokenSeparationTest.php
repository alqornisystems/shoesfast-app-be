<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CustomerGuardTest menguji konfigurasinya. Ini menguji perilakunya: konfigurasi
 * bisa benar sementara rute tetap bocor kalau middleware salah tulis, jadi tes
 * ini menembak rute sungguhan dengan token yang salah jenis.
 */
class TokenSeparationTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('roles_id')->nullable();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Project::create(['name' => 'Cabang Malang']);
    }

    public function test_customer_token_is_rejected_by_staff_endpoint(): void
    {
        $customer = Customer::create(['projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111']);
        $token = $customer->createToken('portal-pelanggan')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_staff_token_is_rejected_by_customer_endpoint(): void
    {
        $user = User::create([
            'projects_id' => 1,
            'name' => 'Admin Malang',
            'phone' => '81311112222',
            'password' => bcrypt('rahasia'),
        ]);
        $token = $user->createToken('web-admin')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/customer/auth/me')
            ->assertStatus(401);
    }
}
