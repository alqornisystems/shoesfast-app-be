<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pagar otorisasi per jabatan pada routes/api.php.
 *
 * Yang diuji di sini HANYA keputusan boleh/tidak boleh — itu satu-satunya hal yang dimiliki
 * middleware `role:`. Test tidak menuntut 200 pada jalur yang diizinkan, karena begitu request
 * lolos pagar, controller-nya menyentuh tabel-tabel lama yang tidak punya migration (produksi
 * adalah sumber kebenaran). Yang penting: request yang berhak TIDAK ditolak 403, dan request
 * yang tidak berhak SELALU ditolak 403.
 *
 * User dan Role sengaja tidak disimpan ke database — relasi `role` dipasang langsung lewat
 * setRelation(), jadi test ini tidak butuh skema sama sekali.
 */
class RoleAuthorizationTest extends TestCase
{
    private function actingAsRole(?string $roleName): void
    {
        $user = new User(['name' => 'Pengguna Uji', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', $roleName === null ? null : new Role(['name' => $roleName]));

        Sanctum::actingAs($user);
    }

    /** Jalur yang dipakai tiap jabatan, beserta siapa yang berhak. */
    public static function matrix(): array
    {
        return [
            // [metode, jalur, jabatan yang berhak]
            'dashboard' => ['get', '/api/dashboard', ['Admin Super', 'Admin', 'Teknisi', 'Kurir', 'HRD', 'Finance', 'Admin Sosmed', 'Admin Crm']],
            'absen hari ini' => ['get', '/api/attendances/today', ['Admin Super', 'Admin', 'Teknisi', 'Kurir', 'HRD', 'Finance', 'Admin Sosmed', 'Admin Crm']],
            'ajukan izin' => ['post', '/api/absences', ['Admin Super', 'Admin', 'Teknisi', 'Kurir', 'HRD', 'Finance', 'Admin Sosmed', 'Admin Crm']],
            'catatan harian' => ['get', '/api/daily-notes', ['Admin Super', 'Admin', 'Teknisi', 'Kurir', 'HRD', 'Finance', 'Admin Sosmed', 'Admin Crm']],
            'baca kalender libur' => ['get', '/api/holidays', ['Admin Super', 'Admin', 'Teknisi', 'Kurir', 'HRD', 'Finance', 'Admin Sosmed', 'Admin Crm']],

            'pengerjaan' => ['get', '/api/treatments', ['Admin Super', 'Admin', 'Teknisi', 'Kurir']],
            'pengiriman' => ['get', '/api/sends', ['Admin Super', 'Admin', 'Teknisi', 'Kurir']],
            'bagi pekerjaan' => ['post', '/api/treatments/assign', ['Admin Super', 'Admin']],

            'pesanan' => ['get', '/api/orders', ['Admin Super', 'Admin']],
            'layanan' => ['get', '/api/services', ['Admin Super', 'Admin']],
            'tulis kalender libur' => ['post', '/api/holidays', ['Admin Super', 'Admin']],
            'tulis cabang' => ['post', '/api/projects', ['Admin Super', 'Admin']],

            'pembayaran' => ['get', '/api/payments', ['Admin Super', 'Admin', 'Finance']],
            'pengeluaran' => ['get', '/api/expenses', ['Admin Super', 'Admin', 'Finance']],
            'laporan laba rugi' => ['get', '/api/reports/profit-loss', ['Admin Super', 'Admin', 'Finance']],

            'data karyawan' => ['get', '/api/users', ['Admin Super', 'Admin', 'HRD']],
            'jabatan' => ['get', '/api/roles', ['Admin Super', 'Admin', 'HRD']],
            'setujui izin' => ['put', '/api/absences/1/approve', ['Admin Super', 'Admin', 'HRD']],
            'laporan absensi' => ['get', '/api/reports/attendance', ['Admin Super', 'Admin', 'HRD']],

            'data pelanggan' => ['get', '/api/customers', ['Admin Super', 'Admin', 'Admin Crm', 'Admin Sosmed']],
            'broadcast' => ['get', '/api/broadcasts/templates', ['Admin Super', 'Admin', 'Admin Crm', 'Admin Sosmed']],
            'laporan iklan' => ['get', '/api/reports/meta-ads', ['Admin Super', 'Admin', 'Admin Crm', 'Admin Sosmed']],
        ];
    }

    private const ALL_ROLES = [
        'Admin Super', 'Admin', 'Teknisi', 'Kurir', 'HRD', 'Finance', 'Admin Sosmed', 'Admin Crm',
    ];

    /**
     * @dataProvider matrix
     */
    public function test_setiap_jalur_hanya_terbuka_untuk_jabatan_yang_berhak(
        string $method,
        string $uri,
        array $allowed,
    ): void {
        foreach (self::ALL_ROLES as $role) {
            $this->actingAsRole($role);

            $status = $this->json(strtoupper($method), $uri)->getStatusCode();

            if (in_array($role, $allowed, true)) {
                $this->assertNotSame(
                    403,
                    $status,
                    "{$role} seharusnya boleh mengakses {$method} {$uri}, tapi ditolak 403.",
                );
            } else {
                $this->assertSame(
                    403,
                    $status,
                    "{$role} seharusnya DITOLAK di {$method} {$uri}, tapi dapat status {$status}.",
                );
            }
        }
    }

    public function test_teknisi_dan_kurir_punya_akses_yang_persis_sama(): void
    {
        $hasil = [];

        foreach (['Teknisi', 'Kurir'] as $role) {
            foreach (self::matrix() as $nama => [$method, $uri, $allowed]) {
                $this->actingAsRole($role);
                $hasil[$role][$nama] = $this->json(strtoupper($method), $uri)->getStatusCode() !== 403;
            }
        }

        $this->assertSame(
            $hasil['Teknisi'],
            $hasil['Kurir'],
            'Teknisi merangkap mengantar, jadi keduanya harus melihat hal yang sama.',
        );
    }

    public function test_akun_tanpa_jabatan_ditolak_dengan_pesan_yang_jelas(): void
    {
        $this->actingAsRole(null);

        $this->getJson('/api/orders')
            ->assertStatus(403)
            ->assertJson(['message' => 'Akun Anda belum memiliki jabatan. Hubungi admin.']);
    }

    public function test_jabatan_yang_tidak_berhak_dapat_pesan_indonesia(): void
    {
        $this->actingAsRole('Teknisi');

        $this->getJson('/api/reports/profit-loss')
            ->assertStatus(403)
            ->assertJson(['message' => 'Jabatan Anda tidak memiliki akses ke menu ini.']);
    }

    public function test_nama_jabatan_dicocokkan_tanpa_peduli_besar_kecil_huruf(): void
    {
        // Tabel `roles` diisi manual sejak lama; selisih huruf tidak boleh mengunci orang berhak.
        $this->actingAsRole('finance');

        $this->assertNotSame(403, $this->getJson('/api/payments')->getStatusCode());
    }

    public function test_tanpa_token_tetap_401_bukan_403(): void
    {
        // Pagar jabatan tidak boleh menutupi kegagalan autentikasi — beda sebab, beda kode.
        $this->getJson('/api/orders')->assertStatus(401);
    }
}
