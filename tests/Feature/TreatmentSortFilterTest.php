<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Project;
use App\Models\Role;
use App\Models\Service;
use App\Models\Treatment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Customer\CreatesCustomerSchema;
use Tests\TestCase;

/**
 * Saringan jenis layanan dan pengurutan pilihan pengguna di GET /api/treatments.
 *
 * Yang dijaga di sini: saringan benar-benar menyaring, pengurutan MENGGANTI urutan
 * bawaan halaman (bukan menumpuknya), dan kolom di luar daftar putih ditolak sebelum
 * menyentuh SQL.
 */
class TreatmentSortFilterTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
        Project::create(['name' => 'Cabang Malang']);

        $admin = new User(['name' => 'Admin Uji', 'projects_id' => 1]);
        $admin->id = 1;
        $admin->setRelation('role', new Role(['name' => 'Admin']));
        Sanctum::actingAs($admin);
    }

    /** Satu treatment menunggu, dengan layanan dan barang bernama sesuai permintaan. */
    private function antrean(string $layanan, string $barang, int $createdAt): Treatment
    {
        $service = Service::firstOrCreate(['name' => $layanan], ['estimation' => 3, 'price' => 50000]);

        $customer = Customer::withoutGlobalScope('branch')->firstOrCreate(
            ['phone' => '81200001111'],
            ['projects_id' => 1, 'name' => 'Budi'],
        );

        $order = Order::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'customers_id' => $customer->id,
            'code' => 'INV'.$createdAt, 'date' => $createdAt,
            'total_price' => 50000, 'status' => 1,
        ]);

        $item = OrderItem::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'orders_id' => $order->id, 'name' => $barang,
        ]);

        $treatment = Treatment::withoutGlobalScope('branch')->create([
            'projects_id' => 1,
            'orders_items_id' => $item->id,
            'services_id' => $service->id,
            'status' => 0,
            'date_start' => $createdAt,
            'price' => 50000,
        ]);

        // `created_at` tidak ada di $fillable dan Eloquent mengisinya sendiri dengan
        // waktu sekarang. Umur antrean adalah yang sedang diuji, jadi nilainya ditulis
        // lewat query builder yang tidak lewat penjaga fillable.
        Treatment::withoutGlobalScope('branch')
            ->where('id', $treatment->id)
            ->update(['created_at' => $createdAt]);

        return $treatment->fresh();
    }

    private function namaLayanan(array $body): array
    {
        return array_column($body['data'], 'services_name');
    }

    public function test_saringan_layanan_hanya_mengembalikan_layanan_itu(): void
    {
        $this->antrean('Cuci Sepatu', 'Sneakers Putih', 1_700_000_000);
        $tas = $this->antrean('Cuci Tas', 'Tote Bag', 1_700_000_100);

        $body = $this->getJson('/api/treatments?page_type=waiting_list&services_id='.$tas->services_id)
            ->assertStatus(200)
            ->json();

        $this->assertSame(['Cuci Tas'], $this->namaLayanan($body));
    }

    public function test_urut_nama_layanan_menggantikan_urutan_bawaan(): void
    {
        // Bawaan waiting list: created_at ASC. Sepatu dicatat lebih dulu, jadi tanpa
        // pengurutan ia yang di atas; menurut abjad, "Cuci Tas" kalah dari "Cuci Sepatu".
        $this->antrean('Cuci Tas', 'Tote Bag', 1_700_000_000);
        $this->antrean('Cuci Sepatu', 'Sneakers Putih', 1_700_000_100);

        $bawaan = $this->getJson('/api/treatments?page_type=waiting_list')->json();
        $this->assertSame(['Cuci Tas', 'Cuci Sepatu'], $this->namaLayanan($bawaan));

        $naik = $this->getJson('/api/treatments?page_type=waiting_list&sort=service&order=asc')->json();
        $this->assertSame(['Cuci Sepatu', 'Cuci Tas'], $this->namaLayanan($naik));

        $turun = $this->getJson('/api/treatments?page_type=waiting_list&sort=service&order=desc')->json();
        $this->assertSame(['Cuci Tas', 'Cuci Sepatu'], $this->namaLayanan($turun));
    }

    public function test_urut_nama_barang_dan_harga_juga_bisa(): void
    {
        $this->antrean('Cuci Sepatu', 'Zebra Sneakers', 1_700_000_000);
        $this->antrean('Cuci Tas', 'Anak Panah', 1_700_000_100);

        $body = $this->getJson('/api/treatments?page_type=waiting_list&sort=item&order=asc')->json();

        $this->assertSame(
            ['Anak Panah', 'Zebra Sneakers'],
            array_column($body['data'], 'orders_items_name'),
        );
    }

    public function test_kolom_di_luar_daftar_putih_ditolak(): void
    {
        $this->getJson('/api/treatments?page_type=waiting_list&sort=treatments.id;DROP')
            ->assertStatus(422);

        $this->getJson('/api/treatments?page_type=waiting_list&sort=service&order=miring')
            ->assertStatus(422);
    }

    public function test_ringkasan_menghitung_seluruh_antrean_per_layanan(): void
    {
        $lama = time() - (20 * 24 * 60 * 60);   // lewat ambang 14 hari
        $baru = time() - (2 * 24 * 60 * 60);

        $this->antrean('Cuci Sepatu', 'Sneakers Putih', $lama);
        $this->antrean('Cuci Sepatu', 'Sneakers Hitam', $baru);
        $ditugaskan = $this->antrean('Cuci Sepatu', 'Sneakers Biru', $baru);
        $ditugaskan->update(['users_id' => 9]);
        $this->antrean('Repaint', 'Vans Old Skool', $baru);

        $body = $this->getJson('/api/treatments/summary-by-service')
            ->assertStatus(200)
            ->json();

        // Cuci Sepatu punya satu yang terlambat, jadi ia di atas.
        $this->assertSame(['Cuci Sepatu', 'Repaint'], array_column($body['data'], 'services_name'));

        $sepatu = $body['data'][0];
        $this->assertSame(3, $sepatu['total']);
        $this->assertSame(2, $sepatu['menunggu']);
        $this->assertSame(1, $sepatu['dikerjakan']);
        $this->assertSame(1, $sepatu['terlambat']);
        $this->assertSame($lama, $sepatu['antrean_terlama']);
    }

    public function test_ringkasan_tidak_menghitung_pekerjaan_yang_sudah_selesai(): void
    {
        $selesai = $this->antrean('Cuci Sepatu', 'Sneakers Putih', time());
        $selesai->update(['done_at' => time()]);

        $body = $this->getJson('/api/treatments/summary-by-service')->assertStatus(200)->json();

        $this->assertSame([], $body['data']);
    }

    public function test_pencarian_menemukan_lewat_nama_layanan(): void
    {
        $this->antrean('Cuci Sepatu', 'Sneakers Putih', 1_700_000_000);
        $this->antrean('Repaint', 'Vans Old Skool', 1_700_000_100);

        $body = $this->getJson('/api/treatments?page_type=waiting_list&search=repaint')
            ->assertStatus(200)
            ->json();

        $this->assertSame(['Repaint'], $this->namaLayanan($body));
    }
}
