<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAbsence;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kalender presensi (GET /api/attendances/daily-status).
 *
 * Urutan prioritas status adalah inti dari endpoint ini: satu tanggal bisa sekaligus hari
 * libur DAN hari orang itu tetap masuk, dan salah urut berarti kerjanya hilang dari catatan.
 */
class DailyStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('projects', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('is_deleted')->default(0);
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('users_id')->nullable();
            $t->integer('clock')->nullable();
            $t->integer('type')->nullable();
            $t->integer('is_wfa')->default(0);
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
        });

        Schema::create('attendances_absences', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('users_id')->nullable();
            $t->integer('type')->default(1);
            $t->integer('date_start')->nullable();
            $t->integer('date_end')->nullable();
            $t->integer('is_approval')->default(0);
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('holidays', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->nullable();
            $t->integer('date')->nullable();
            $t->string('name')->nullable();
            $t->text('description')->nullable();
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });
    }

    private function actingAsKurir(): void
    {
        $user = new User(['name' => 'Kurir Uji', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => 'Kurir']));

        Sanctum::actingAs($user);
    }

    private function statusPada(string $tanggal, array $data): ?string
    {
        foreach ($data as $baris) {
            if ($baris['date'] === $tanggal) {
                return $baris['status'];
            }
        }

        return null;
    }

    public function test_menggabungkan_hadir_izin_dan_libur_dalam_satu_rentang(): void
    {
        $this->actingAsKurir();

        Attendance::create([
            'projects_id' => 1, 'users_id' => 1,
            'clock' => strtotime('2026-08-03 08:00'), 'type' => 0,
        ]);

        AttendanceAbsence::create([
            'projects_id' => 1, 'users_id' => 1, 'type' => 1,
            'date_start' => strtotime('2026-08-04'),
            'date_end' => strtotime('2026-08-05 23:59'),
            'is_approval' => 1,
        ]);

        Holiday::create([
            'projects_id' => null,
            'date' => strtotime('2026-08-17'),
            'name' => 'HUT RI',
        ]);

        $data = $this->getJson('/api/attendances/daily-status?start_date=2026-08-01&end_date=2026-08-20')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('present', $this->statusPada('2026-08-03', $data));
        $this->assertSame('absence', $this->statusPada('2026-08-04', $data));
        $this->assertSame('absence', $this->statusPada('2026-08-05', $data));
        $this->assertSame('holiday', $this->statusPada('2026-08-17', $data));
        // 2 Agustus 2026 jatuh hari Minggu.
        $this->assertSame('weekend', $this->statusPada('2026-08-02', $data));
        // Hari kerja tanpa absensi dan tanpa izin.
        $this->assertSame('absent', $this->statusPada('2026-08-06', $data));
    }

    /** Orang yang tetap masuk di hari libur memang hadir — kerjanya tidak boleh tertimpa. */
    public function test_hadir_menang_atas_hari_libur(): void
    {
        $this->actingAsKurir();

        Attendance::create([
            'projects_id' => 1, 'users_id' => 1,
            'clock' => strtotime('2026-08-17 08:00'), 'type' => 0,
        ]);
        Holiday::create(['projects_id' => null, 'date' => strtotime('2026-08-17'), 'name' => 'HUT RI']);

        $data = $this->getJson('/api/attendances/daily-status?start_date=2026-08-17&end_date=2026-08-17')
            ->assertStatus(200)->json('data');

        $this->assertSame('present', $data[0]['status']);
    }

    /** Izin yang belum disetujui bukan ketidakhadiran yang sah. */
    public function test_izin_belum_disetujui_tidak_dihitung(): void
    {
        $this->actingAsKurir();

        AttendanceAbsence::create([
            'projects_id' => 1, 'users_id' => 1, 'type' => 1,
            'date_start' => strtotime('2026-08-06'),
            'date_end' => strtotime('2026-08-06 23:59'),
            'is_approval' => 0,
        ]);

        $data = $this->getJson('/api/attendances/daily-status?start_date=2026-08-06&end_date=2026-08-06')
            ->assertStatus(200)->json('data');

        $this->assertSame('absent', $data[0]['status']);
    }

    /** Hari yang belum terjadi bukan alpa — kalau tidak, sisa bulan tercat merah semua. */
    public function test_hari_mendatang_tidak_dihitung_alpa(): void
    {
        $this->actingAsKurir();

        // Hari kerja, bukan sekadar "besok": kalau besok jatuh Minggu, statusnya
        // 'weekend' — benar, tapi bukan yang diuji di sini. Test yang bergantung pada
        // hari apa ia dijalankan akan merah sendiri suatu Sabtu tanpa ada yang berubah.
        $besok = date('Y-m-d', strtotime('+1 day'));
        while (date('N', strtotime($besok)) === '7') {
            $besok = date('Y-m-d', strtotime($besok.' +1 day'));
        }
        $data = $this->getJson("/api/attendances/daily-status?start_date={$besok}&end_date={$besok}")
            ->assertStatus(200)->json('data');

        $this->assertSame('upcoming', $data[0]['status']);
    }

    public function test_rentang_terbalik_ditolak(): void
    {
        $this->actingAsKurir();

        $this->getJson('/api/attendances/daily-status?start_date=2026-08-20&end_date=2026-08-01')
            ->assertStatus(422);
    }
}
