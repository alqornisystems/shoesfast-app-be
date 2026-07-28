<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PinResetTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);
        Mail::fake();
    }

    public function test_forgot_pin_reports_email_channel_when_email_exists(): void
    {
        Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'email' => 'budi@example.test', 'pin' => bcrypt('123456'),
        ]);

        $this->postJson('/api/customer/auth/forgot-pin', ['phone' => '081200001111'])
            ->assertStatus(200)
            ->assertJson(['channel' => 'email']);
    }

    public function test_forgot_pin_directs_to_the_shop_when_no_email(): void
    {
        // 3.781 dari 4.664 pelanggan tidak punya email.
        Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'email' => null, 'pin' => bcrypt('123456'),
        ]);

        $this->postJson('/api/customer/auth/forgot-pin', ['phone' => '81200001111'])
            ->assertStatus(200)
            ->assertJson(['channel' => 'toko']);
    }

    public function test_forgot_pin_does_not_reveal_whether_a_number_exists(): void
    {
        // Jawaban untuk nomor tak dikenal harus tidak bisa dibedakan dari
        // nomor yang ada tanpa email, kalau tidak endpoint ini jadi alat
        // penyapu nomor pelanggan.
        $unknown = $this->postJson('/api/customer/auth/forgot-pin', ['phone' => '81299990000'])
            ->assertStatus(200)->json();

        $this->assertSame('toko', $unknown['channel']);
    }

    public function test_forgot_pin_replaces_the_old_pin_and_revokes_sessions(): void
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'email' => 'budi@example.test', 'pin' => bcrypt('123456'),
        ]);
        $customer->createToken('portal-pelanggan');

        $this->postJson('/api/customer/auth/forgot-pin', ['phone' => '81200001111'])
            ->assertStatus(200);

        $fresh = Customer::withoutGlobalScope('branch')->find($customer->id);
        $this->assertFalse(password_verify('123456', $fresh->pin), 'PIN lama harus tidak berlaku');
        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_admin_reset_clears_pin_and_provenance(): void
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('123456'), 'pin_created_at' => 1000, 'pin_created_ip' => '1.2.3.4',
        ]);

        $this->resetPinAsAdmin($customer->id)->assertStatus(200);

        $fresh = Customer::withoutGlobalScope('branch')->find($customer->id);
        $this->assertNull($fresh->pin);
        $this->assertNull($fresh->pin_created_at);
        $this->assertNull($fresh->pin_created_ip);
    }

    public function test_reset_revokes_existing_customer_tokens(): void
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('123456'),
        ]);
        $customer->createToken('portal-pelanggan');

        $this->resetPinAsAdmin($customer->id)->assertStatus(200);

        // Kalau token pengklaim tetap hidup, mereset PIN tidak menolongnya.
        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_crm_role_cannot_reset_a_pin(): void
    {
        $customer = Customer::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Budi', 'phone' => '81200001111',
            'pin' => bcrypt('123456'),
        ]);

        $this->resetPinAsAdmin($customer->id, 'Admin Crm')->assertStatus(403);

        $this->assertNotNull(Customer::withoutGlobalScope('branch')->find($customer->id)->pin);
    }

    private function resetPinAsAdmin(int $customerId, string $roleName = 'Admin')
    {
        $user = new User(['name' => 'Admin', 'projects_id' => 1]);
        $user->id = 1;
        // Relasi dipasang langsung, bukan lewat query: tabel `roles` tidak ada
        // di skema tes ini. Pola sama dengan InvoiceShareLinkTest.
        $user->setRelation('role', new Role(['name' => $roleName]));

        Sanctum::actingAs($user);

        return $this->postJson('/api/customers/'.$customerId.'/reset-pin');
    }
}
