<?php

namespace Tests\Feature\Customer;

use App\Models\Project;
use App\Models\Reward;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRewardTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
        Service::create(['name' => 'Deep Clean', 'price' => 75000]);
    }

    private function adminAktif(string $roleName = 'Admin'): void
    {
        $user = new User(['name' => 'Admin', 'projects_id' => 1]);
        $user->id = 1;
        // Tabel roles tidak ada di skema tes ini, jadi relasi dipasang langsung.
        $user->setRelation('role', new Role(['name' => $roleName]));

        Sanctum::actingAs($user);
    }

    public function test_admin_creates_a_reward(): void
    {
        $this->adminAktif();

        $this->postJson('/api/rewards', [
            'name' => 'Cuci Gratis',
            'type' => 0,
            'services_id' => 1,
            'points_cost' => 50,
            'is_active' => 1,
        ])->assertStatus(201);

        $this->assertSame(1, Reward::withoutGlobalScope('branch')->count());
    }

    public function test_reward_list_is_paginated_and_named(): void
    {
        $this->adminAktif();
        Reward::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Cuci Gratis', 'type' => 0,
            'services_id' => 1, 'points_cost' => 50, 'is_active' => 1,
        ]);

        $body = $this->getJson('/api/rewards')->assertStatus(200)->json();

        $this->assertSame(1, $body['total']);
        $this->assertSame('Cuci Gratis', $body['data'][0]['name']);
        $this->assertSame('Layanan', $body['data'][0]['type_label']);
        $this->assertSame('Deep Clean', $body['data'][0]['service_name']);
    }

    public function test_reward_list_survives_a_deleted_service(): void
    {
        // Layanan bisa dihapus setelah hadiah dibuat; barisnya tidak boleh
        // menjatuhkan seluruh daftar.
        $this->adminAktif();
        Reward::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Hadiah Yatim', 'type' => 0,
            'services_id' => 999, 'points_cost' => 20, 'is_active' => 1,
        ]);

        $body = $this->getJson('/api/rewards')->assertStatus(200)->json();

        $this->assertNull($body['data'][0]['service_name']);
    }

    public function test_delete_is_soft_and_hides_from_customers(): void
    {
        $this->adminAktif();
        $reward = Reward::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Cuci Gratis', 'type' => 0,
            'points_cost' => 50, 'is_active' => 1,
        ]);

        $this->deleteJson('/api/rewards/'.$reward->id)->assertStatus(200);

        // Baris tetap ada di tabel, tapi tidak pernah terbaca lagi.
        $this->assertSame(0, Reward::withoutGlobalScope('branch')->count());
        $this->assertSame(
            1,
            DB::table('rewards')->where('id', $reward->id)->where('is_deleted', 1)->count(),
        );
    }

    public function test_points_cost_must_be_positive(): void
    {
        $this->adminAktif();

        $this->postJson('/api/rewards', [
            'name' => 'Gratisan', 'type' => 1, 'points_cost' => 0, 'is_active' => 1,
        ])->assertStatus(422);
    }

    public function test_technician_cannot_manage_rewards(): void
    {
        $this->adminAktif('Teknisi');

        $this->getJson('/api/rewards')->assertStatus(403);
        $this->postJson('/api/rewards', [
            'name' => 'Curang', 'type' => 1, 'points_cost' => 1, 'is_active' => 1,
        ])->assertStatus(403);
    }
}
