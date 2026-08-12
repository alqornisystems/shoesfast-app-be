<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Send;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak layar profil staf dan status pembayaran di daftar pengiriman.
 *
 * Foto profil dulu ditulis apa adanya ke kolom `photo`. Untuk berkas 2 MB — batas yang
 * diizinkan layar karyawan — itu berarti ~2,7 juta karakter base64 masuk ke kolom TEXT
 * berbatas 65.535 byte: MySQL memotongnya diam-diam dan yang tersimpan adalah data URL
 * rusak. Tidak ada galat di mana pun; fotonya sekadar tidak pernah bisa dibuka lagi.
 */
class ProfilFotoTest extends TestCase
{
    use CreatesFieldTaskSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFieldTaskSchema();
    }

    private function actingAsStaf(): User
    {
        $user = User::create([
            'name' => 'Renno',
            'phone' => '81299998888',
            'email' => 'renno@shoesfast.id',
            'projects_id' => 1,
        ]);
        $user->setRelation('role', new Role(['name' => 'Teknisi']));

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_foto_profil_disimpan_sebagai_jalur_bukan_base64(): void
    {
        Storage::fake('public');
        $user = $this->actingAsStaf();

        $isi = 'sebuah-foto';

        $this->putJson('/api/auth/profile', [
            'name' => 'Renno',
            'photo' => 'data:image/png;base64,'.base64_encode($isi),
        ])->assertStatus(200);

        $tersimpan = $user->fresh()->photo;

        $this->assertStringStartsWith('users/', $tersimpan);
        $this->assertStringEndsWith('.png', $tersimpan);
        $this->assertSame($isi, Storage::disk('public')->get($tersimpan));
    }

    /** Bentuknya harus sama dengan login dan /auth/me, supaya klien tidak perlu memuat ulang. */
    public function test_balasan_profil_sama_bentuknya_dengan_auth_me(): void
    {
        $this->actingAsStaf();

        $balasan = $this->putJson('/api/auth/profile', ['name' => 'Renno Baru'])
            ->assertStatus(200)
            ->json('user');

        foreach (['id', 'name', 'email', 'phone', 'photo', 'role', 'projects_id', 'project_name', 'is_super_admin'] as $kunci) {
            $this->assertArrayHasKey($kunci, $balasan);
        }

        $this->assertSame('Renno Baru', $balasan['name']);
    }

    /** Nomor telepon adalah identitas login — salah ketik satu digit mengunci diri sendiri. */
    public function test_nomor_telepon_tidak_bisa_diubah_sendiri(): void
    {
        $user = $this->actingAsStaf();

        $this->putJson('/api/auth/profile', [
            'name' => 'Renno',
            'phone' => '81200000000',
        ])->assertStatus(200);

        $this->assertSame('81299998888', $user->fresh()->phone);
    }

    /** Sebagian staf lapangan tidak punya email; memaksanya membuat profil tak bisa disimpan. */
    public function test_email_boleh_kosong(): void
    {
        $this->actingAsStaf();

        $this->putJson('/api/auth/profile', ['name' => 'Renno'])->assertStatus(200);
    }

    public function test_daftar_pengiriman_membawa_status_pembayaran_tanpa_nominal(): void
    {
        $user = $this->actingAsStaf();

        $order = Order::create([
            'projects_id' => 1, 'customers_id' => 1, 'code' => 'INV1',
            'date' => time(), 'status' => 0, 'total_price' => 100000,
        ]);
        Payment::create([
            'projects_id' => 1, 'orders_id' => $order->id,
            'date' => time(), 'nominal' => 40000,
        ]);
        Send::create([
            'projects_id' => 1, 'users_id' => $user->id, 'orders_id' => $order->id,
            'date' => time(), 'status' => Send::STATUS_BERJALAN, 'type' => 1,
        ]);

        $baris = $this->getJson('/api/sends/in-progress')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertSame('partial', $baris['payment_status']);
        // Harga tetap tidak dibawa ke daftar — itu bukan urusan kurir.
        $this->assertArrayNotHasKey('total_price', $baris);
        $this->assertArrayNotHasKey('total_paid', $baris);
    }

    public function test_pesanan_tanpa_harga_berstatus_unpriced_di_daftar(): void
    {
        $user = $this->actingAsStaf();

        $order = Order::create([
            'projects_id' => 1, 'customers_id' => 1, 'code' => 'INV2',
            'date' => time(), 'status' => 0, 'total_price' => null,
        ]);
        Send::create([
            'projects_id' => 1, 'users_id' => $user->id, 'orders_id' => $order->id,
            'date' => time(), 'status' => Send::STATUS_BERJALAN, 'type' => 1,
        ]);

        $this->getJson('/api/sends/in-progress')
            ->assertStatus(200)
            ->assertJsonPath('data.0.payment_status', 'unpriced');
    }
}
