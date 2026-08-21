<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Role;
use App\Models\Send;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pelacakan kurir oleh pelanggan: penerbitan token, pengiriman posisi, endpoint publik,
 * dan pencabutan.
 *
 * Sebagian besar test di sini menjaga apa yang TIDAK boleh keluar. Tautannya bisa
 * diteruskan ke siapa saja, jadi tiap kunci di balasan publik adalah keputusan tentang
 * apa yang boleh dilihat orang asing — dan kebocoran di sini tidak bisa ditarik kembali
 * setelah tautannya beredar.
 */
class CourierTrackingTest extends TestCase
{
    use CreatesFieldTaskSchema;

    private const KURIR = 5;

    private const KURIR_LAIN = 6;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFieldTaskSchema();
    }

    private function masukSebagaiKurir(int $id = self::KURIR): User
    {
        // id disetel langsung, bukan lewat mass assignment: `id` tidak ada di $fillable
        // User, jadi User::create(['id' => 5]) diam-diam membuat baris ber-id 1 dan
        // seluruh pencocokan users_id meleset.
        $user = User::find($id);

        if (! $user) {
            $user = new User(['name' => 'Renno Saputra', 'phone' => '81299998888', 'projects_id' => 1]);
            $user->id = $id;
            $user->save();
        }

        $user->setRelation('role', new Role(['name' => 'Kurir']));

        Sanctum::actingAs($user);

        return $user;
    }

    private function tugas(int $milik = self::KURIR, bool $adaKoordinat = true): Send
    {
        $pelanggan = Customer::create([
            'projects_id' => 1,
            'name' => 'Budi',
            'phone' => '81200001111',
            'address' => 'Jl. Melati 10',
            'latitude' => $adaKoordinat ? -7.9553004 : null,
            'longitude' => $adaKoordinat ? 112.5873766 : null,
        ]);

        $order = Order::create([
            'projects_id' => 1, 'customers_id' => $pelanggan->id, 'code' => 'INV'.uniqid(),
            'date' => time(), 'status' => 0, 'total_price' => 150000,
        ]);

        return Send::create([
            'projects_id' => 1, 'users_id' => $milik, 'orders_id' => $order->id,
            'date' => time(), 'status' => Send::STATUS_BERJALAN, 'type' => 1,
        ]);
    }

    /* ------------------------------------------------ B-LACAK-1: token */

    public function test_berangkat_menerbitkan_token_dan_tautan(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();

        $balasan = $this->postJson("/api/sends/{$send->id}/start")
            ->assertStatus(200)
            ->assertJsonStructure(['started_at', 'tracking_token', 'tracking_url', 'tracking_expires_at'])
            ->json();

        // Acak, bukan turunan id: tautan yang bisa ditebak berarti posisi karyawan bisa
        // dibaca siapa pun yang iseng menghitung.
        // 40 karakter heksadesimal dari random_bytes — bukan turunan id. Bahwa ia bukan
        // turunan id dibuktikan test berikutnya (dua tugas, dua token berbeda), bukan
        // dengan mencari digit id di dalamnya: token acak hampir pasti memuat digit apa pun.
        $this->assertSame(40, strlen($balasan['tracking_token']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $balasan['tracking_token']);
        $this->assertStringContainsString('/lacak/'.$balasan['tracking_token'], $balasan['tracking_url']);
        $this->assertGreaterThan(time(), $balasan['tracking_expires_at']);
    }

    /** Tombol berangkat ditekan berkali-kali saat sinyal buruk; tautannya jangan berganti. */
    public function test_berangkat_dua_kali_tidak_menerbitkan_token_baru(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();

        $satu = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');
        $dua = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');

        $this->assertSame($satu, $dua);
    }

    public function test_tugas_kedua_mendapat_token_sendiri(): void
    {
        $this->masukSebagaiKurir();

        $a = $this->postJson("/api/sends/{$this->tugas()->id}/start")->json('tracking_token');
        $b = $this->postJson("/api/sends/{$this->tugas()->id}/start")->json('tracking_token');

        $this->assertNotSame($a, $b);
    }

    /* ---------------------------------------------- B-LACAK-4: posisi */

    public function test_kurir_mengirim_posisi_tugasnya(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $this->postJson("/api/sends/{$send->id}/start");

        $this->postJson("/api/sends/{$send->id}/location", [
            'latitude' => -7.9601,
            'longitude' => 112.5910,
            'accuracy' => 12.5,
        ])->assertStatus(200);

        $send->refresh();
        $this->assertEqualsWithDelta(-7.9601, (float) $send->courier_latitude, 0.0001);
        $this->assertNotNull($send->courier_position_at);
    }

    public function test_kurir_tidak_bisa_mengirim_posisi_tugas_kurir_lain(): void
    {
        $this->masukSebagaiKurir(self::KURIR);
        $send = $this->tugas(self::KURIR_LAIN);

        $this->postJson("/api/sends/{$send->id}/location", [
            'latitude' => -7.96, 'longitude' => 112.59,
        ])->assertStatus(404);
    }

    /* ------------------------------------- B-LACAK-3: endpoint publik */

    public function test_halaman_publik_menampilkan_posisi_dan_jarak(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $token = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');

        $this->postJson("/api/sends/{$send->id}/location", [
            'latitude' => -7.9601, 'longitude' => 112.5910, 'accuracy' => 12.5,
        ]);

        // Tanpa Authorization sama sekali — inilah intinya.
        $isi = $this->getJson("/api/lacak/{$token}")->assertStatus(200)->json();

        $this->assertSame('on_the_way', $isi['status']);
        $this->assertSame('Renno', $isi['courier']['name']);
        $this->assertNotNull($isi['courier_position']);
        $this->assertFalse($isi['courier_position']['is_stale']);
        // Titik uji berjarak ratusan meter dari tujuan.
        $this->assertGreaterThan(100, $isi['distance_meters']);
    }

    /**
     * Inti privasinya. Tautan ini bisa diteruskan ke siapa saja, jadi yang tidak boleh
     * keluar harus benar-benar tidak keluar.
     */
    public function test_balasan_publik_tidak_membocorkan_nomor_kurir_alamat_atau_tagihan(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $token = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');

        $mentah = $this->getJson("/api/lacak/{$token}")->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('81299998888', $mentah, 'Nomor kurir bocor.');
        $this->assertStringNotContainsString('Saputra', $mentah, 'Nama lengkap kurir bocor.');
        $this->assertStringNotContainsString('Jl. Melati', $mentah, 'Alamat tertulis bocor.');
        $this->assertStringNotContainsString('150000', $mentah, 'Nominal tagihan bocor.');
        $this->assertStringNotContainsString('81200001111', $mentah, 'Nomor pelanggan bocor.');
    }

    public function test_posisi_basi_mengaku_basi(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $token = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');

        $send->update([
            'courier_latitude' => -7.9601,
            'courier_longitude' => 112.5910,
            'courier_position_at' => time() - 900,
        ]);

        $isi = $this->getJson("/api/lacak/{$token}")->assertStatus(200)->json();

        // Kurir yang masuk terowongan tidak boleh terlihat seperti kurir yang berhenti
        // bekerja — tapi halaman harus tahu bahwa titiknya sudah lama tidak bergerak.
        $this->assertTrue($isi['courier_position']['is_stale']);
    }

    public function test_kurir_di_depan_alamat_berstatus_arrived(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $token = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');

        // ~20 meter dari titik tujuan.
        $this->postJson("/api/sends/{$send->id}/location", [
            'latitude' => -7.9553904, 'longitude' => 112.5874866,
        ]);

        $this->getJson("/api/lacak/{$token}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'arrived');
    }

    public function test_token_ngawur_dijawab_kedaluwarsa_bukan_galat(): void
    {
        $this->getJson('/api/lacak/token-yang-tidak-pernah-ada')
            ->assertStatus(200)
            ->assertJsonPath('status', 'expired')
            ->assertJsonPath('courier', null);
    }

    public function test_tautan_mati_setelah_masa_berlakunya_lewat(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $token = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');

        $send->update(['tracking_expires_at' => time() - 60]);

        $this->getJson("/api/lacak/{$token}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'expired');
    }

    /* ------------------------------------------------ pencabutan */

    public function test_tugas_selesai_mencabut_tautan_dan_menghapus_posisi(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $token = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');
        $this->postJson("/api/sends/{$send->id}/location", [
            'latitude' => -7.96, 'longitude' => 112.59,
        ]);

        $this->postJson('/api/sends/mark-completed', ['ids' => [$send->id]])->assertStatus(200);

        $send->refresh();
        $this->assertNull($send->tracking_token);
        $this->assertNull($send->courier_latitude, 'Posisi terakhir harus ikut dihapus.');

        $this->getJson("/api/lacak/{$token}")->assertJsonPath('status', 'expired');
    }

    public function test_tugas_gagal_juga_mencabut_tautan(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $token = $this->postJson("/api/sends/{$send->id}/start")->json('tracking_token');

        $this->postJson("/api/sends/{$send->id}/failed", ['reason_code' => 'customer_absent'])
            ->assertStatus(200);

        $this->assertNull($send->fresh()->tracking_token);
        $this->getJson("/api/lacak/{$token}")->assertJsonPath('status', 'expired');
    }

    public function test_tugas_berakhir_menolak_kiriman_posisi_baru(): void
    {
        $this->masukSebagaiKurir();
        $send = $this->tugas();
        $this->postJson("/api/sends/{$send->id}/start");
        $this->postJson("/api/sends/{$send->id}/failed", ['reason_code' => 'other']);

        $this->postJson("/api/sends/{$send->id}/location", [
            'latitude' => -7.96, 'longitude' => 112.59,
        ])->assertStatus(422);
    }
}
