<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Catatan harian menahan absen pulang (POST /api/attendances/clock-out).
 *
 * Aturan yang dipindahkan dari API lama: hari tanpa catatan ditolak, catatan yang masih
 * terbuka juga ditolak. Bedanya, pembebasan tidak lagi memakai parameter `is_web` yang bisa
 * dikirim siapa saja, melainkan jabatan — itulah yang dipagari di sini.
 *
 * Tabel-tabel lama tidak punya migration (produksi adalah sumber kebenaran), jadi test ini
 * membangun sendiri kolom seadanya yang disentuh jalur clock-out di sqlite memori.
 */
class ClockOutDailyNoteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->integer('is_deleted')->default(0);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('projects_id')->nullable();
            $table->integer('users_id')->nullable();
            $table->integer('clock')->nullable();
            $table->integer('type')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->float('distance')->nullable();
            $table->integer('is_wfa')->default(0);
            $table->integer('is_deleted')->default(0);
            $table->integer('created_by')->nullable();
            $table->integer('created_at')->nullable();
        });

        Schema::create('attendances_absences', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('projects_id')->nullable();
            $table->integer('users_id')->nullable();
            $table->integer('date_start')->nullable();
            $table->integer('date_end')->nullable();
            $table->integer('is_approval')->default(0);
            $table->integer('is_deleted')->default(0);
        });

        // Catatan harian tinggal di tabel `issues`, bukan `daily_notes`.
        Schema::create('issues', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('projects_id')->nullable();
            $table->integer('users_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->integer('date')->nullable();
            $table->integer('status')->default(0);
            $table->integer('is_deleted')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('modified_at')->nullable();
        });
    }

    private function actingAsRole(string $roleName): User
    {
        $user = new User(['name' => 'Pengguna Uji', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => $roleName]));

        Sanctum::actingAs($user);

        return $user;
    }

    /** Absen masuk hari ini supaya jalurnya sampai ke pemeriksaan catatan harian. */
    private function sudahAbsenMasuk(): void
    {
        \App\Models\Attendance::create([
            'projects_id' => 1,
            'users_id' => 1,
            'clock' => strtotime('today') + 3600,
            'type' => 0,
            'latitude' => '-7.9',
            'longitude' => '112.6',
            'distance' => 0,
            'is_wfa' => 0,
            'created_by' => 1,
        ]);
    }

    private function catatanHariIni(int $status): void
    {
        \App\Models\DailyNote::create([
            'projects_id' => 1,
            'users_id' => 1,
            'title' => 'Catatan uji',
            'description' => 'Kegiatan',
            'date' => strtotime('today') + 3600,
            'status' => $status,
        ]);
    }

    private function clockOut()
    {
        return $this->postJson('/api/attendances/clock-out', [
            'latitude' => -7.9,
            'longitude' => 112.6,
        ]);
    }

    public function test_kurir_tanpa_catatan_harian_tidak_bisa_absen_pulang(): void
    {
        $this->actingAsRole('Kurir');
        $this->sudahAbsenMasuk();

        $this->clockOut()
            ->assertStatus(422)
            ->assertJson(['message' => 'Anda belum membuat catatan harian hari ini. Buat catatan dulu sebelum absen pulang.']);
    }

    public function test_teknisi_dengan_catatan_yang_belum_selesai_tidak_bisa_absen_pulang(): void
    {
        $this->actingAsRole('Teknisi');
        $this->sudahAbsenMasuk();
        $this->catatanHariIni(0);

        $respons = $this->clockOut();

        $respons->assertStatus(422);
        $this->assertStringContainsString(
            'belum diselesaikan',
            $respons->json('message'),
            'Pesan penolakan harus menyebut catatan yang belum diselesaikan, bukan catatan yang belum ada.',
        );
    }

    public function test_catatan_yang_sudah_selesai_melewatkan_penjagaan(): void
    {
        $this->actingAsRole('Kurir');
        $this->sudahAbsenMasuk();
        $this->catatanHariIni(1);

        // Lolos penjagaan catatan harian; berhenti di pemeriksaan berikutnya (lokasi cabang
        // belum ada isinya di tabel projects). Yang diuji: bukan lagi soal catatan.
        $this->clockOut()
            ->assertStatus(422)
            ->assertJson(['message' => 'Lokasi cabang belum diatur. Hubungi admin.']);
    }

    public function test_admin_dibebaskan_dari_aturan_catatan_harian(): void
    {
        $this->actingAsRole('Admin');
        $this->sudahAbsenMasuk();

        // Tanpa catatan sama sekali, tapi tidak ditahan aturan catatan harian.
        $this->clockOut()
            ->assertStatus(422)
            ->assertJson(['message' => 'Lokasi cabang belum diatur. Hubungi admin.']);
    }
}
