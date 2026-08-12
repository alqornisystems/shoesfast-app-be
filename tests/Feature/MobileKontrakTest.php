<?php

namespace Tests\Feature;

use App\Models\AttendanceAbsence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kontrak balasan yang dipegang aplikasi mobile staf.
 *
 * Aplikasi Flutter mengurai kunci-kunci ini dengan parser toleran karena bentuk baliknya
 * sempat harus ditebak. Test di sini mengunci kunci yang dijanjikan dokumen supaya
 * penghapusan atau penggantian namanya ketahuan di sini, bukan lewat layar yang kosong
 * di tangan kurir.
 *
 * Tabel lama tidak punya migration (produksi adalah sumber kebenaran), jadi kolom yang
 * disentuh dibangun sendiri di sqlite memori — dengan TIPE yang sama seperti produksi.
 */
class MobileKontrakTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('is_deleted')->default(0);
        });

        // Daftar izin memuat relasi `user` untuk menampilkan nama pengaju.
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->text('photo')->nullable();
            $table->integer('roles_id')->nullable();
            $table->integer('projects_id')->nullable();
            $table->integer('is_deleted')->default(0);
        });

        Schema::create('attendances_absences', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('projects_id')->nullable();
            $table->integer('users_id')->nullable();
            $table->integer('type')->default(1);
            $table->integer('date_start')->nullable();
            $table->integer('date_end')->nullable();
            $table->integer('total_days')->nullable();
            $table->text('note')->nullable();
            $table->text('photo')->nullable();
            $table->integer('is_approval')->default(0);
            $table->integer('is_deleted')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('modified_at')->nullable();
            $table->integer('modified_by')->nullable();
        });
    }

    private function actingAsKurir(): User
    {
        $user = new User([
            'name' => 'Kurir Uji',
            'phone' => '81200001111',
            'photo' => 'users/kurir.jpg',
            'projects_id' => 1,
        ]);
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => 'Kurir']));

        Sanctum::actingAs($user);

        return $user;
    }

    /** B4: sesi yang dipulihkan tidak boleh kehilangan telepon dan foto. */
    public function test_auth_me_mengirim_telepon_foto_dan_radius_absensi(): void
    {
        $this->actingAsKurir();

        $this->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('user.phone', '81200001111')
            // `role` sengaja tidak diperiksa di sini: /auth/me memanggil load(['role'])
            // yang membaca ulang relasi dari database, sehingga relasi tiruan milik test
            // ini tertimpa. Pagar jabatan sudah punya testnya sendiri di
            // RoleAuthorizationTest.
            ->assertJsonPath('attendance.radius_meters', 1000)
            // Jalur relatif dinormalkan jadi URL penuh, bukan diserahkan mentah.
            ->assertJsonPath('user.photo', asset('storage/users/kurir.jpg'));
    }

    /** B9: kolom photo menyimpan tiga bentuk berbeda; semuanya keluar sebagai URL. */
    public function test_daftar_izin_menormalkan_foto_apa_pun_bentuk_simpanannya(): void
    {
        $this->actingAsKurir();

        // Unggahan lama: nama berkas telanjang, foldernya harus disimpulkan.
        AttendanceAbsence::create([
            'projects_id' => 1,
            'users_id' => 1,
            'type' => 1,
            'date_start' => strtotime('2026-08-01'),
            'date_end' => strtotime('2026-08-02'),
            'total_days' => 2,
            'note' => 'Lama',
            'photo' => 'absence_123.jpg',
            'is_approval' => 0,
        ]);

        $this->getJson('/api/absences')
            ->assertStatus(200)
            ->assertJsonPath('data.0.photo', asset('storage/absences/absence_123.jpg'));
    }

    /** B7: rentang tanggal dulu diabaikan diam-diam. */
    public function test_daftar_izin_menghormati_rentang_tanggal(): void
    {
        $this->actingAsKurir();

        foreach (['2025-03-01', '2026-08-01'] as $tanggal) {
            AttendanceAbsence::create([
                'projects_id' => 1,
                'users_id' => 1,
                'type' => 1,
                'date_start' => strtotime($tanggal),
                'date_end' => strtotime($tanggal),
                'total_days' => 1,
                'note' => $tanggal,
                'is_approval' => 1,
            ]);
        }

        $this->getJson('/api/absences?start_date=2026-01-01&end_date=2026-12-31')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.note', '2026-08-01');
    }

    /**
     * B9: awalan data URL dulu ikut ter-decode, jadi berkasnya tersimpan tapi isinya
     * rusak — tidak ada galat, hanya foto yang tidak pernah bisa dibuka.
     */
    public function test_foto_izin_disimpan_utuh_dengan_ekstensi_yang_benar(): void
    {
        Storage::fake('public');
        $this->actingAsKurir();

        $isiAsli = 'sebuah-png-palsu';

        $this->postJson('/api/absences', [
            'type' => 1,
            'date_start' => '2026-08-10',
            'date_end' => '2026-08-11',
            'note' => 'Ada urusan keluarga',
            'photo' => 'data:image/png;base64,'.base64_encode($isiAsli),
        ])->assertStatus(201);

        $jalur = AttendanceAbsence::first()->photo;

        $this->assertStringStartsWith('absences/', $jalur);
        $this->assertStringEndsWith('.png', $jalur);
        $this->assertSame($isiAsli, Storage::disk('public')->get($jalur));
    }
}
