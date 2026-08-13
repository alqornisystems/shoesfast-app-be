<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\Send;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mengambil tugas pengiriman dari antrean (POST /api/sends/{id}/claim).
 *
 * Yang dijaga paling ketat: tugas yang sudah dipegang orang tidak boleh direbut. Sebelum
 * endpoint ini ada, satu-satunya jalan menugaskan diri sendiri adalah rute edit — dan rute
 * itu menimpa users_id dengan diri sendiri TANPA memeriksa pemilik sebelumnya, jadi kurir
 * bisa memindahkan tugas kurir lain ke dirinya hanya dengan menebak sebuah id.
 */
class AmbilTugasTest extends TestCase
{
    use CreatesFieldTaskSchema;

    private const KURIR = 5;

    private const KURIR_LAIN = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFieldTaskSchema();
    }

    private function actingAsKurir(int $id = self::KURIR): User
    {
        $user = new User(['name' => "Kurir {$id}", 'projects_id' => 1]);
        $user->id = $id;
        $user->setRelation('role', new Role(['name' => 'Kurir']));

        Sanctum::actingAs($user);

        return $user;
    }

    private function tugas(?int $milik = null, int $status = Send::STATUS_BERJALAN): Send
    {
        $order = Order::create([
            'projects_id' => 1, 'customers_id' => 1, 'code' => 'INV1',
            'date' => time(), 'status' => 0, 'total_price' => 100000,
        ]);

        return Send::create([
            'projects_id' => 1, 'users_id' => $milik, 'orders_id' => $order->id,
            'date' => time(), 'status' => $status, 'type' => 0,
        ]);
    }

    public function test_tugas_antrean_tanpa_kurir_bisa_diambil(): void
    {
        $this->actingAsKurir();
        $send = $this->tugas(null);

        $this->postJson("/api/sends/{$send->id}/claim")
            ->assertStatus(200)
            ->assertJsonPath('users_id', self::KURIR);

        $this->assertSame(self::KURIR, (int) $send->fresh()->users_id);
    }

    public function test_tugas_milik_kurir_lain_tidak_bisa_direbut(): void
    {
        $this->actingAsKurir(self::KURIR);
        $send = $this->tugas(self::KURIR_LAIN);

        $this->postJson("/api/sends/{$send->id}/claim")->assertStatus(422);

        $this->assertSame(self::KURIR_LAIN, (int) $send->fresh()->users_id);
    }

    /** Mengambil ulang tugas sendiri bukan kesalahan — jaringan lambat, tombol ditekan lagi. */
    public function test_mengambil_ulang_tugas_sendiri_tetap_berhasil(): void
    {
        $this->actingAsKurir();
        $send = $this->tugas(self::KURIR);

        $this->postJson("/api/sends/{$send->id}/claim")->assertStatus(200);
    }

    public function test_tugas_yang_sudah_selesai_tidak_bisa_diambil(): void
    {
        $this->actingAsKurir();
        $send = $this->tugas(null, Send::STATUS_SELESAI);

        $this->postJson("/api/sends/{$send->id}/claim")->assertStatus(422);
    }

    /**
     * Lubang yang ditutup bersamaan: rute edit menimpa users_id dengan diri sendiri untuk
     * non-admin, tapi dulu tanpa memeriksa siapa pemilik sebelumnya.
     */
    public function test_rute_edit_tidak_bisa_dipakai_merebut_tugas(): void
    {
        $this->actingAsKurir(self::KURIR);
        $send = $this->tugas(self::KURIR_LAIN);

        $this->putJson("/api/sends/{$send->id}", [
            'users_id' => self::KURIR,
            'date' => date('Y-m-d'),
        ])->assertStatus(404);

        $this->assertSame(self::KURIR_LAIN, (int) $send->fresh()->users_id);
    }
}
