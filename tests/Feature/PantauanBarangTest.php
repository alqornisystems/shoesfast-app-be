<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Layar Pantauan Barang: cap waktu barang masuk, saringan per teknisi, dan urutan stabil
 * di GET /api/treatments.
 *
 * Yang dijaga paling ketat: saringan `users_id` TIDAK boleh dihormati untuk staf lapangan.
 * Kalau dihormati, pekerjaan teknisi lain bisa dibaca hanya dengan menebak sebuah id.
 */
class PantauanBarangTest extends TestCase
{
    use CreatesFieldTaskSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFieldTaskSchema();

        Schema::create('services', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->string('name')->nullable();
            $t->integer('price')->default(0);
            $t->integer('estimation')->nullable();
            $t->text('photo')->nullable();
            $t->text('description')->nullable();
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('treatments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('users_id')->nullable();
            $t->integer('partnerships_id')->nullable();
            $t->integer('orders_items_id')->nullable();
            $t->integer('services_id')->nullable();
            $t->integer('status')->default(0);
            $t->integer('date_start')->nullable();
            $t->integer('date_end')->nullable();
            $t->integer('started_at')->nullable();
            $t->text('note')->nullable();
            $t->integer('price')->nullable();
            $t->integer('is_partnerships')->default(0);
            $t->integer('done_at')->nullable();
            $t->integer('is_deleted')->default(0);
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        \App\Models\Service::create([
            'projects_id' => 1, 'name' => 'Deep Clean', 'price' => 35000, 'estimation' => 3,
        ]);
    }

    private function masukSebagai(string $jabatan, int $id = 1): User
    {
        $user = new User(['name' => "Uji {$id}", 'projects_id' => 1]);
        $user->id = $id;
        $user->setRelation('role', new Role(['name' => $jabatan]));

        Sanctum::actingAs($user);

        return $user;
    }

    private function pekerjaan(?int $teknisi, string $namaBarang = 'Nike AF1', ?int $masuk = null): Treatment
    {
        $masuk ??= strtotime('2026-08-10 09:00');

        $order = Order::create([
            'projects_id' => 1, 'customers_id' => 1, 'code' => 'INV'.uniqid(),
            'date' => $masuk, 'status' => 0, 'total_price' => 100000,
            'created_at' => $masuk,
        ]);

        $item = OrderItem::create([
            'projects_id' => 1, 'orders_id' => $order->id, 'name' => $namaBarang,
            'type' => 2, 'status' => 0, 'created_at' => $masuk,
        ]);

        // created_at tidak fillable, jadi Eloquent menimpanya dengan waktu sekarang saat
        // insert. Dipaksa lewat query builder supaya selisih "barang masuk" vs "pekerjaan
        // dicatat" benar-benar ada di data yang diuji.
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['created_at' => $masuk]);
        \Illuminate\Support\Facades\DB::table('orders_items')->where('id', $item->id)->update(['created_at' => $masuk]);

        return Treatment::create([
            'projects_id' => 1, 'users_id' => $teknisi, 'orders_items_id' => $item->id,
            'services_id' => 1, 'status' => 0,
            'date_start' => strtotime('2026-08-12 09:00'),
            'date_end' => strtotime('2026-08-15 09:00'),
            // Sengaja jauh setelah barangnya masuk — inilah selisih yang bikin
            // "Tercatat" tidak sama dengan "Masuk".
            'created_at' => strtotime('2026-08-12 09:00'),
        ]);
    }

    public function test_baris_membawa_cap_waktu_barang_masuk(): void
    {
        $this->masukSebagai('Admin');
        $this->pekerjaan(null);

        $baris = $this->getJson('/api/treatments?page_type=waiting_list')
            ->assertStatus(200)
            ->json('data.0');

        $masuk = strtotime('2026-08-10 09:00');

        $this->assertSame($masuk, $baris['orders_date']);
        $this->assertSame($masuk, $baris['orders_created_at']);
        $this->assertSame($masuk, $baris['orders_items_created_at']);
        // Cap waktu lama tetap dikirim, dan memang BEDA dari waktu barang masuk —
        // itulah seluruh alasan kunci baru ini ada.
        $this->assertNotNull($baris['created_at']);
        $this->assertNotSame($masuk, $baris['created_at']);
    }

    public function test_admin_bisa_menyaring_per_teknisi(): void
    {
        $this->masukSebagai('Admin', 99);
        $this->pekerjaan(12);
        $this->pekerjaan(13);

        $this->getJson('/api/treatments?page_type=pengerjaan&users_id=12')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.users_id', 12);
    }

    /** Menghormatinya untuk staf lapangan berarti pekerjaan orang lain bisa dibaca. */
    public function test_teknisi_tidak_bisa_mengintip_pekerjaan_teknisi_lain(): void
    {
        $this->masukSebagai('Teknisi', 12);
        $this->pekerjaan(12);
        $this->pekerjaan(13);

        $data = $this->getJson('/api/treatments?page_type=pengerjaan&users_id=13')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(12, $data[0]['users_id']);
    }

    public function test_pencarian_menjangkau_nama_barang(): void
    {
        $this->masukSebagai('Admin', 99);
        $this->pekerjaan(12, 'Nike Air Force 1');
        $this->pekerjaan(12, 'Adidas Samba');

        $this->getJson('/api/treatments?page_type=pengerjaan&search=samba')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.orders_items_name', 'Adidas Samba');
    }

    /** Seluruh baris bertanggal identik — kalau urutan tidak stabil, ini yang menangkap. */
    public function test_halaman_tidak_tumpang_tindih(): void
    {
        $this->masukSebagai('Admin', 99);

        for ($i = 0; $i < 20; $i++) {
            $this->pekerjaan(12);
        }

        $satu = $this->getJson('/api/treatments?page_type=pengerjaan&per_page=10&page=1')->json('data');
        $dua = $this->getJson('/api/treatments?page_type=pengerjaan&per_page=10&page=2')->json('data');

        $idSatu = array_column($satu, 'id');
        $idDua = array_column($dua, 'id');

        $this->assertEmpty(array_intersect($idSatu, $idDua), 'Ada baris yang muncul di dua halaman.');
        $this->assertCount(20, array_unique(array_merge($idSatu, $idDua)), 'Ada baris yang hilang.');
    }
}
