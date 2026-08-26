<?php

namespace Tests\Feature;

use App\Models\Partnership;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Customer\CreatesCustomerSchema;
use Tests\TestCase;

/**
 * Semua endpoint DAFTAR memakai satu bentuk paginasi: medan datar seperti paginator
 * Laravel, bukan objek `pagination` bersarang.
 *
 * Tiga endpoint dulu menyimpang (broadcast templates, broadcast index, partnerships)
 * dan frontend menambal selisihnya sendiri — mitra-kerja bahkan menghitung `from`/`to`
 * dengan tangan. Test ini yang menahan supaya bentuknya tidak bercabang lagi.
 *
 * Endpoint LAPORAN sengaja tidak ikut: responsnya bukan daftar polos melainkan
 * `{summary, breakdown…, data, pagination}`, dan di sana objek bersarang justru yang
 * memisahkan meta paginasi dari meta laporan.
 */
class BentukPaginasiDaftarTest extends TestCase
{
    use CreatesCustomerSchema;

    private const MEDAN_PAGINATOR = [
        'current_page', 'last_page', 'per_page', 'total', 'from', 'to',
    ];

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

    public function test_daftar_mitra_memakai_medan_paginator_datar(): void
    {
        Partnership::withoutGlobalScope('branch')->create([
            'projects_id' => 1, 'name' => 'Mitra Sol', 'phone' => '81200002222',
        ]);

        $body = $this->getJson('/api/partnerships?page=1&per_page=15')
            ->assertStatus(200)
            ->assertJsonStructure(array_merge(['data'], self::MEDAN_PAGINATOR))
            ->json();

        $this->assertArrayNotHasKey('pagination', $body, 'Daftar tidak boleh membungkus meta paginasi.');
        $this->assertSame(1, $body['current_page']);
        $this->assertSame(1, $body['last_page']);
        $this->assertSame(1, $body['total']);
        $this->assertSame(1, $body['from']);
        $this->assertSame(1, $body['to']);
    }

    public function test_daftar_kosong_memberi_from_dan_to_null(): void
    {
        $body = $this->getJson('/api/partnerships')->assertStatus(200)->json();

        $this->assertSame(0, $body['total']);
        // Paginator Laravel memberi null, bukan 0, saat tidak ada baris — bentuknya
        // ikut supaya frontend boleh memperlakukan semua daftar dengan cara sama.
        $this->assertNull($body['from']);
        $this->assertNull($body['to']);
        // last_page tidak pernah 0: "halaman 1 dari 0" tidak punya arti.
        $this->assertSame(1, $body['last_page']);
    }
}
