<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Reward;
use App\Models\RewardRedemption;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardRedemptionTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
    }

    private function customer(int $points): Customer
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'is_member' => 1, 'points' => $points,
        ]);

        Sanctum::actingAs($customer, ['*'], 'customer');

        return $customer;
    }

    private function reward(int $cost = 50): Reward
    {
        return Reward::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Cuci Gratis', 'type' => 0,
            'points_cost' => $cost, 'is_active' => 1,
        ]);
    }

    public function test_catalog_marks_which_rewards_are_affordable(): void
    {
        $this->customer(60);
        $this->reward(50);
        Reward::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Tas Shoesfast', 'type' => 1,
            'points_cost' => 200, 'is_active' => 1,
        ]);

        $body = $this->getJson('/api/customer/rewards')->assertStatus(200)->json();

        $this->assertTrue($body['data'][0]['affordable']);
        $this->assertFalse($body['data'][1]['affordable']);
    }

    public function test_inactive_rewards_are_hidden(): void
    {
        $this->customer(100);
        $this->reward(50)->update(['is_active' => 0]);

        $this->getJson('/api/customer/rewards')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_redeem_deducts_points_and_returns_a_code(): void
    {
        $customer = $this->customer(60);
        $reward = $this->reward(50);

        $body = $this->postJson('/api/customer/rewards/'.$reward->id.'/redeem')
            ->assertStatus(201)->json();

        $this->assertNotEmpty($body['code']);
        $this->assertSame(50, $body['points_spent']);
        $this->assertSame(10, $body['points_left']);
        $this->assertSame(10, (int) Customer::withoutGlobalScope('branch')->find($customer->id)->points);

        $redemption = RewardRedemption::withoutGlobalScope('branch')->first();
        $this->assertSame(0, (int) $redemption->status);
        $this->assertSame($customer->id, (int) $redemption->customers_id);
    }

    public function test_redeem_with_insufficient_points_is_rejected(): void
    {
        $customer = $this->customer(10);
        $reward = $this->reward(50);

        $this->postJson('/api/customer/rewards/'.$reward->id.'/redeem')
            ->assertStatus(422)
            ->assertJson(['message' => 'Poin kamu belum cukup untuk hadiah ini.']);

        $this->assertSame(10, (int) Customer::withoutGlobalScope('branch')->find($customer->id)->points);
        $this->assertSame(0, RewardRedemption::withoutGlobalScope('branch')->count());
    }

    public function test_points_can_never_go_negative(): void
    {
        // Dua penukaran berturut-turut dengan saldo yang hanya cukup untuk satu.
        $customer = $this->customer(50);
        $reward = $this->reward(50);

        $this->postJson('/api/customer/rewards/'.$reward->id.'/redeem')->assertStatus(201);
        $this->postJson('/api/customer/rewards/'.$reward->id.'/redeem')->assertStatus(422);

        $this->assertSame(0, (int) Customer::withoutGlobalScope('branch')->find($customer->id)->points);
        $this->assertSame(1, RewardRedemption::withoutGlobalScope('branch')->count());
    }

    public function test_reward_from_another_branch_is_not_redeemable(): void
    {
        Project::create(['name' => 'Cabang Surabaya']);
        $this->customer(100);

        $otherBranchReward = Reward::withoutGlobalScope('branch')->create([
            'projects_id' => 2, 'name' => 'Hadiah Surabaya', 'type' => 1,
            'points_cost' => 10, 'is_active' => 1,
        ]);

        $this->postJson('/api/customer/rewards/'.$otherBranchReward->id.'/redeem')
            ->assertStatus(404);
    }

    public function test_redemption_history_lists_own_codes(): void
    {
        $this->customer(100);
        $reward = $this->reward(50);

        $this->postJson('/api/customer/rewards/'.$reward->id.'/redeem')->assertStatus(201);

        $body = $this->getJson('/api/customer/redemptions')->assertStatus(200)->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('Cuci Gratis', $body['data'][0]['reward_name']);
        $this->assertSame(50, $body['data'][0]['points_spent']);
    }
}
