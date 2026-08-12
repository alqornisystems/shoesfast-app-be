<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pendaftaran token FCM perangkat (POST/DELETE /api/auth/device-token).
 *
 * Yang dijaga di sini bukan pengirimannya (itu urusan FcmService yang punya gerbang
 * FCM_ENABLED sendiri), melainkan kepemilikan token: notifikasi tugas yang salah alamat
 * berarti seorang kurir membaca pekerjaan orang lain di layar kuncinya.
 */
class DeviceTokenTest extends TestCase
{
    use CreatesFieldTaskSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFieldTaskSchema();
    }

    private function actingAsUser(int $id): User
    {
        $user = new User(['name' => "Kurir {$id}", 'projects_id' => 1]);
        $user->id = $id;
        $user->setRelation('role', new Role(['name' => 'Kurir']));

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_token_tersimpan_atas_nama_pengguna_yang_login(): void
    {
        $this->actingAsUser(7);

        $this->postJson('/api/auth/device-token', [
            'token' => 'fcm-abc',
            'platform' => 'android',
        ])->assertStatus(200);

        $baris = DeviceToken::first();
        $this->assertSame(7, (int) $baris->users_id);
        $this->assertSame('android', $baris->platform);
    }

    /** Kurir di sinyal buruk akan mendaftar berkali-kali. */
    public function test_mendaftar_ulang_tidak_menghasilkan_baris_ganda(): void
    {
        $this->actingAsUser(7);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/device-token', [
                'token' => 'fcm-abc',
                'platform' => 'android',
            ])->assertStatus(200);
        }

        $this->assertSame(1, DeviceToken::withoutGlobalScope('notDeleted')->count());
    }

    /**
     * FCM memindahkan token ketika sebuah perangkat dipakai orang lain. Tanpa pemindahan
     * kepemilikan, tugas kurir baru akan terus terkirim ke HP pemilik lama.
     */
    public function test_token_yang_sama_berpindah_ke_pengguna_baru(): void
    {
        $this->actingAsUser(7);
        $this->postJson('/api/auth/device-token', ['token' => 'fcm-abc'])->assertStatus(200);

        $this->actingAsUser(9);
        $this->postJson('/api/auth/device-token', ['token' => 'fcm-abc'])->assertStatus(200);

        $this->assertSame(1, DeviceToken::withoutGlobalScope('notDeleted')->count());
        $this->assertSame(9, (int) DeviceToken::first()->users_id);
    }

    public function test_hapus_token_hanya_menyentuh_milik_sendiri(): void
    {
        $this->actingAsUser(7);
        $this->postJson('/api/auth/device-token', ['token' => 'milik-tujuh'])->assertStatus(200);

        $this->actingAsUser(9);
        $this->postJson('/api/auth/device-token', ['token' => 'milik-sembilan'])->assertStatus(200);

        // Pengguna 9 mencoba menghapus token milik pengguna 7.
        $this->deleteJson('/api/auth/device-token', ['token' => 'milik-tujuh'])->assertStatus(200);

        $this->assertSame(
            1,
            DeviceToken::where('token', 'milik-tujuh')->count(),
            'Token milik pengguna lain tidak boleh ikut terhapus.'
        );
    }

    /** Baris yang sudah dihapus lunak harus hidup lagi, bukan menabrak kunci unik. */
    public function test_token_yang_pernah_dihapus_bisa_didaftarkan_lagi(): void
    {
        $this->actingAsUser(7);

        $this->postJson('/api/auth/device-token', ['token' => 'fcm-abc'])->assertStatus(200);
        $this->deleteJson('/api/auth/device-token', ['token' => 'fcm-abc'])->assertStatus(200);
        $this->postJson('/api/auth/device-token', ['token' => 'fcm-abc'])->assertStatus(200);

        $this->assertSame(1, DeviceToken::count(), 'Token aktif harus ada tepat satu.');
    }
}
