<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Umur sesi admin web: siapa yang boleh mengusir siapa dari sebuah sesi.
 *
 * Tiga keluhan "tiba-tiba logout sendiri" bermuara ke satu hal — token yang
 * masih dipakai orang dihanguskan di tempat lain. Yang diuji di sini adalah
 * batas itu, bukan bentuk balasannya:
 *
 *  1. login di perangkat kedua TIDAK mematikan sesi perangkat pertama;
 *  2. /auth/refresh menyisakan tenggang untuk token lama, karena permintaan yang
 *     sedang di jalan masih membawanya;
 *  3. token yang memang sudah kedaluwarsa tetap dibersihkan, supaya tabelnya
 *     tidak tumbuh tanpa batas sekarang setelah login berhenti menyapu semuanya.
 *
 * Tabel-tabelnya legacy dan tidak punya migration (produksi sumber kebenaran),
 * jadi test membangun sendiri yang dipakai jalur ini.
 */
class SesiTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('projects', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->decimal('latitude', 10, 8)->nullable();
            $t->decimal('longitude', 11, 8)->nullable();
            $t->integer('is_deleted')->default(0);
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('is_deleted')->default(0);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->text('photo')->nullable();
            $t->integer('roles_id')->nullable();
            $t->integer('projects_id')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('modified_by')->nullable();
            $t->integer('is_deleted')->default(0);
        });

        Schema::create('user_preferences', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('users_id');
            $t->integer('active_branch_id')->nullable();
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

    private function buatPengguna(): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Admin Uji',
            'phone' => '81234567890',
            'password' => Hash::make('rahasia'),
            'projects_id' => null,
            'is_deleted' => 0,
        ])->save();

        return $user->fresh();
    }

    /**
     * Paksa guard dibuat ulang sebelum permintaan berikutnya.
     *
     * `RequestGuard` menyimpan pengguna yang sudah berhasil diselesaikan, dan di
     * dalam satu test instance aplikasinya dipakai ulang antar permintaan. Tanpa
     * ini, permintaan kedua lolos karena pengguna hasil permintaan pertama masih
     * tersimpan — tokennya tidak pernah benar-benar diperiksa lagi, dan test yang
     * seharusnya menguji keabsahan token jadi selalu hijau.
     */
    private function lupakanGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** @return string token mentah */
    private function login(): string
    {
        return $this->postJson('/api/auth/login', [
            'phone' => '081234567890',
            'password' => 'rahasia',
        ])->assertOk()->json('token');
    }

    public function test_login_di_perangkat_lain_tidak_mengusir_sesi_yang_sedang_dipakai(): void
    {
        $this->buatPengguna();

        $tokenPerangkatPertama = $this->login();

        // Orang yang sama membuka admin di HP, atau rekannya memakai akun yang sama.
        $this->login();

        $this->lupakanGuard();
        $this->withToken($tokenPerangkatPertama)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_refresh_menyisakan_tenggang_untuk_token_lama(): void
    {
        $this->buatPengguna();

        $tokenLama = $this->login();

        $this->lupakanGuard();
        $tokenBaru = $this->withToken($tokenLama)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->json('token');

        $this->assertNotSame($tokenLama, $tokenBaru);

        // Permintaan yang sudah di jalan saat penukaran terjadi masih membawa
        // token lama. Kalau ia dijawab 401, klien menganggap sesinya habis.
        $this->lupakanGuard();
        $this->withToken($tokenLama)
            ->getJson('/api/auth/me')
            ->assertOk();

        $this->lupakanGuard();
        $this->withToken($tokenBaru)
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_tenggang_token_lama_tidak_lebih_dari_beberapa_menit(): void
    {
        $this->buatPengguna();

        $tokenLama = $this->login();
        $idLama = (int) explode('|', $tokenLama)[0];

        $this->lupakanGuard();
        $this->withToken($tokenLama)->postJson('/api/auth/refresh')->assertOk();

        $barisLama = \Laravel\Sanctum\PersonalAccessToken::find($idLama);

        $this->assertNotNull($barisLama, 'Token lama harus diberi tenggang, bukan dihapus seketika.');

        $kedaluwarsa = $barisLama->expires_at;
        $this->assertNotNull($kedaluwarsa);
        $this->assertTrue(
            $kedaluwarsa->lessThanOrEqualTo(now()->addMinutes(5)),
            'Token lama harus mati dalam hitungan menit, bukan tetap hidup sepanjang umur aslinya.',
        );
    }

    /**
     * Angka yang dijanjikan ke orangnya. Tulisan di halaman login berbunyi
     * "Ingat saya selama 30 hari", dan tulisan yang tidak sama dengan umur
     * tokennya adalah cara paling pasti membuat orang mengira aplikasinya
     * mengeluarkan dia tanpa sebab.
     */
    public function test_umur_token_sesuai_yang_dijanjikan_di_halaman_login(): void
    {
        $this->buatPengguna();

        $biasa = $this->postJson('/api/auth/login', [
            'phone' => '081234567890',
            'password' => 'rahasia',
        ])->assertOk()->json('expires_at');

        $this->lupakanGuard();

        $ingatSaya = $this->postJson('/api/auth/login', [
            'phone' => '081234567890',
            'password' => 'rahasia',
            'remember_me' => true,
        ])->assertOk()->json('expires_at');

        // Toleransi semenit: detik yang berjalan di antara dua permintaan.
        $this->assertTrue(
            now()->addDay()->diffInMinutes($biasa, true) <= 1,
            'Tanpa centang, token harus berumur 1 hari.',
        );
        $this->assertTrue(
            now()->addDays(30)->diffInMinutes($ingatSaya, true) <= 1,
            'Dengan "Ingat saya", token harus berumur 30 hari — sama dengan tulisan di halaman login.',
        );
    }

    public function test_login_membersihkan_token_yang_sudah_kedaluwarsa(): void
    {
        $user = $this->buatPengguna();

        $user->createToken('web-admin', ['*'], now()->subDay());
        $this->assertSame(1, $user->tokens()->count());

        $this->login();

        $this->assertSame(
            0,
            $user->tokens()->whereNotNull('expires_at')->where('expires_at', '<', now())->count(),
        );
    }
}
