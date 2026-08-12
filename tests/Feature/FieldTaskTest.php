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
 * Alur tugas lapangan kurir: berangkat, bayar di tempat, bukti serah terima, gagal,
 * dan rekap harian.
 *
 * Yang dijaga paling ketat adalah jalur uang. Sebelum endpoint ini ada, kurir menagih
 * tunai di depan pelanggan dan uangnya berpindah tangan tanpa jejak apa pun di sistem.
 */
class FieldTaskTest extends TestCase
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

    /** @return array{0: Order, 1: Send} */
    private function tugas(?int $harga = 150000, int $milik = self::KURIR, int $status = Send::STATUS_BERJALAN): array
    {
        $order = Order::create([
            'projects_id' => 1,
            'customers_id' => 1,
            'code' => 'INV2026080001',
            'date' => time(),
            'status' => 0,
            'total_price' => $harga,
        ]);

        $send = Send::create([
            'projects_id' => 1,
            'users_id' => $milik,
            'orders_id' => $order->id,
            'date' => time(),
            'status' => $status,
            'type' => 1,
        ]);

        return [$order, $send];
    }

    /* ------------------------------------------------------------ B1 uang */

    public function test_kurir_mencatat_pembayaran_dan_sisa_tagihan_ikut_turun(): void
    {
        $this->actingAsKurir();
        [$order, $send] = $this->tugas(150000);

        $this->postJson("/api/sends/{$send->id}/payment", [
            'amount' => 150000,
            'method' => 'cash',
        ])->assertStatus(200)
            ->assertJsonPath('total_paid', 150000)
            ->assertJsonPath('credit', 0)
            ->assertJsonPath('payment_status', 'paid');

        $pembayaran = Payment::first();
        $this->assertSame($order->id, (int) $pembayaran->orders_id);
        $this->assertSame(150000, (int) $pembayaran->nominal);
        // Metode tidak punya kolomnya sendiri; disematkan ke catatan.
        $this->assertStringContainsString('Tunai', $pembayaran->note);
    }

    public function test_pembayaran_sebagian_diizinkan(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas(150000);

        $this->postJson("/api/sends/{$send->id}/payment", [
            'amount' => 50000,
            'method' => 'transfer',
        ])->assertStatus(200)
            ->assertJsonPath('credit', 100000)
            ->assertJsonPath('payment_status', 'partial');
    }

    public function test_nominal_melebihi_sisa_tagihan_ditolak(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas(150000);

        $this->postJson("/api/sends/{$send->id}/payment", [
            'amount' => 200000,
            'method' => 'cash',
        ])->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }

    /**
     * Pesanan portal pelanggan lahir tanpa harga. Kalau nol dianggap harga, sisa tagihan
     * jadi negatif dan penolakannya berbunyi membingungkan.
     */
    public function test_pesanan_tanpa_harga_tidak_bisa_ditagih(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas(null);

        $this->postJson("/api/sends/{$send->id}/payment", [
            'amount' => 50000,
            'method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_kurir_tidak_bisa_menyentuh_tugas_kurir_lain(): void
    {
        $this->actingAsKurir(self::KURIR);
        [, $send] = $this->tugas(150000, self::KURIR_LAIN);

        // 404, bukan 403: keberadaan tugas orang lain pun tidak perlu dibocorkan.
        $this->postJson("/api/sends/{$send->id}/payment", [
            'amount' => 10000,
            'method' => 'cash',
        ])->assertStatus(404);

        $this->postJson("/api/sends/{$send->id}/start")->assertStatus(404);
        $this->postJson("/api/sends/{$send->id}/failed", ['reason_code' => 'other'])->assertStatus(404);
    }

    /* ------------------------------------------------------- B2 bukti */

    public function test_bukti_serah_terima_disimpan_dengan_jalur_folder(): void
    {
        Storage::fake('public');
        $this->actingAsKurir();
        [, $send] = $this->tugas();

        $this->postJson("/api/sends/{$send->id}/proof", [
            'photo' => 'data:image/png;base64,'.base64_encode('foto-palsu'),
            'receiver_name' => 'Budi',
            'latitude' => -7.78,
            'longitude' => 110.36,
        ])->assertStatus(200)
            ->assertJsonStructure(['photo_url']);

        $send->refresh();
        $this->assertStringStartsWith('sends/', $send->proof_photo);
        $this->assertSame('Budi', $send->receiver_name);
        $this->assertNotNull($send->proof_at);
    }

    /* -------------------------------------------------------- B3 gagal */

    public function test_tugas_gagal_menyimpan_alasan_dan_tanggal_jadwal_ulang(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas();

        $this->postJson("/api/sends/{$send->id}/failed", [
            'reason_code' => 'rescheduled',
            'note' => 'rumah kosong',
            'reschedule_date' => '2026-08-20',
        ])->assertStatus(200);

        $send->refresh();
        $this->assertSame(Send::STATUS_GAGAL, $send->status);
        $this->assertSame('rescheduled', $send->reason_code);
        $this->assertSame(strtotime('2026-08-20'), $send->reschedule_date);
    }

    /** Daftar alasan tertutup — teks bebas tidak bisa dilaporkan. */
    public function test_alasan_di_luar_daftar_ditolak(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas();

        $this->postJson("/api/sends/{$send->id}/failed", [
            'reason_code' => 'macet_parah',
        ])->assertStatus(422);
    }

    public function test_jadwal_ulang_tanpa_tanggal_ditolak(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas();

        $this->postJson("/api/sends/{$send->id}/failed", [
            'reason_code' => 'rescheduled',
        ])->assertStatus(422);
    }

    public function test_tugas_yang_sudah_selesai_tidak_bisa_ditandai_gagal(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas(150000, self::KURIR, Send::STATUS_SELESAI);

        $this->postJson("/api/sends/{$send->id}/failed", [
            'reason_code' => 'other',
        ])->assertStatus(422);
    }

    /* ----------------------------------------------------- B4 berangkat */

    public function test_menekan_berangkat_dua_kali_tidak_menggeser_jam_pertama(): void
    {
        $this->actingAsKurir();
        [, $send] = $this->tugas();

        $pertama = $this->postJson("/api/sends/{$send->id}/start")
            ->assertStatus(200)->json('started_at');

        // Kurir di sinyal buruk menekan ulang; jam berangkat yang sebenarnya harus bertahan.
        $kedua = $this->postJson("/api/sends/{$send->id}/start")
            ->assertStatus(200)->json('started_at');

        $this->assertSame($pertama, $kedua);
    }

    /* ------------------------------------------------------- B7 rekap */

    public function test_rekap_harian_memisahkan_kejadian_hari_ini_dari_antrean(): void
    {
        $this->actingAsKurir();

        // Selesai hari ini.
        [, $selesai] = $this->tugas(50000);
        $selesai->update(['status' => Send::STATUS_SELESAI, 'modified_at' => time()]);
        Payment::create([
            'projects_id' => 1,
            'orders_id' => $selesai->orders_id,
            'date' => time(),
            'nominal' => 50000,
        ]);

        // Gagal hari ini.
        [, $gagal] = $this->tugas(50000);
        $gagal->update(['status' => Send::STATUS_GAGAL, 'failed_at' => time()]);

        // Masih berjalan, dan sengaja dari KEMARIN: antrean yang menumpuk justru paling
        // perlu terlihat hari ini, jadi ia tidak boleh tersaring oleh tanggal.
        [, $kemarin] = $this->tugas(50000);
        $kemarin->update(['date' => strtotime('-1 day'), 'modified_at' => strtotime('-1 day')]);

        $this->getJson('/api/sends/summary')
            ->assertStatus(200)
            ->assertJsonPath('completed', 1)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('collected', 50000);
    }
}
