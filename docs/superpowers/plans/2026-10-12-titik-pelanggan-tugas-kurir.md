# Titik Pelanggan untuk Tugas Kurir — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin bisa melihat — dan kalau kosong, menaruh — titik peta pelanggan di layar tempat tugas kurir dibuat, tanpa meninggalkan aplikasi.

**Architecture:** `customers.maps` (URL Google Maps) tetap satu-satunya yang dipegang manusia; `customers.latitude`/`longitude` jadi turunannya, diisi oleh hook `saving` di model `Customer` supaya kelima jalur tulis ikut benar tanpa diingat. Satu migrasi memanen 3.913 koordinat yang sudah terkubur di URL lama. Admin dapat satu komponen tanda-titik plus dialog peta, dipakai tiga halaman, yang menyimpan lewat satu endpoint sempit `PUT /api/customers/{id}/point`.

**Tech Stack:** Laravel 12 / PHP 8.2 / MariaDB (produksi) · PHPUnit · Next.js 16 + React 19 + TypeScript · Leaflet + react-leaflet · shadcn/ui · Tailwind v4

**Spec:** `docs/superpowers/specs/2026-10-12-titik-pelanggan-tugas-kurir-design.md`

## Global Constraints

- PHP dan MySQL dari XAMPP tidak ada di `PATH` non-interaktif. Setiap perintah `php`/`composer`/`mysql` wajib diawali: `export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"`
- Konvensi skema legacy, produksi sumber kebenaran: waktu unix integer (`$dateFormat = 'U'`), `const UPDATED_AT = 'modified_at'` (tidak ada `updated_at`), hapus lunak lewat kolom `is_deleted`, foreign key jamak (`users_id`, `projects_id`).
- Migrasi baru wajib idempoten terhadap produksi yang sudah termigrasi: jaga dengan `Schema::hasTable` / `Schema::hasColumn`.
- Setiap migrasi wajib punya padanan SQL di `docs/sql-perubahan-db.md` — deploy FTP tidak menjalankan migrasi.
- Formatter backend: `./vendor/bin/pint`. Wajib `{"result":"pass"}` sebelum commit.
- Rute aksi khusus didaftarkan **sebelum** `Route::apiResource(...)` untuk resource yang sama.
- Tidak ada dependensi baru di repo mana pun. Leaflet, react-leaflet, shadcn/ui, dan sonner semuanya sudah terpasang.
- Semua teks yang dilihat pengguna di `shoesfast-app-fe` berbahasa Indonesia.
- `shoesfast-app-fe` tidak punya framework test. Gerbangnya `npm run build` dan `npx eslint <berkas yang disentuh>`.
- Koordinat dikirim ke klien sebagai **angka** (`(float)`), bukan string, mengikuti `SendController` baris ≈803 dan ≈916 yang sudah begitu.
- Kolom produksi: `latitude decimal(10,8)`, `longitude decimal(11,8)`. Jangan diubah.

## Review Focus

Lima kelas masukan yang tersirat di spec tapi tidak dijamin oleh tugas mana pun kalau tidak ditulis di sini. Tiap baris sudah dibuatkan test di tugas yang memiliki kodenya.

1. **URL Google Maps tanpa `@lat,lng`, misal format `!3d-7.9!4d112.5`.** Harus dilaporkan sebagai "tidak ada titik", bukan melempar galat atau menyimpan angka separuh. → Task 1.
2. **`@0,0`.** Lolos pemeriksaan rentang ±90/±180 tapi menunjuk Teluk Guinea. Pin palsu di peta kurir lebih buruk daripada tidak ada pin, jadi **ditolak sebagai tidak ada titik**. → Task 1.
3. **`PUT /point` dengan `{"maps": null}`.** Harus mengosongkan titik dan membalas 200, bukan 500 — admin yang sadar titiknya salah butuh jalan membatalkannya. → Task 3.
4. **Pelanggan yang sudah dihapus lunak (`is_deleted = 1`).** `PUT /point` harus 404 (global scope `notDeleted`), dan backfill harus melewatinya tanpa menghitungnya sebagai gagal. → Task 3 dan Task 5.
5. **`maps` dikosongkan sementara `latitude`/`longitude` dikirim eksplisit dalam satu simpanan yang sama.** Ketiga aturan di spec saling bertabrakan di sini. Resolusinya: **URL yang bisa diparse selalu menang; kalau URL kosong atau tidak memuat titik, angka yang dikirim eksplisit dihormati; kalau tak ada keduanya, koordinat dikosongkan.** → Task 2.

---

### Task 1: Parser URL → koordinat

**Files:**
- Create: `app/Support/MapsPoint.php`
- Test: `tests/Unit/MapsPointTest.php`

**Interfaces:**
- Consumes: tidak ada.
- Produces: `App\Support\MapsPoint::fromUrl(?string $url): ?array` — mengembalikan `['latitude' => float, 'longitude' => float]` atau `null`. Dipakai Task 2 dan Task 5.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Unit/MapsPointTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\MapsPoint;
use PHPUnit\Framework\TestCase;

/**
 * Parser URL Google Maps → koordinat.
 *
 * Bentuk yang nyata ada di produksi: 3.913 dari 3.916 baris `customers.maps`
 * memuat `@lat,lng`, 173 di antaranya memakai domain google.co.id. Yang tiga
 * sisanya bukan URL peta sama sekali.
 */
class MapsPointTest extends TestCase
{
    public function test_membaca_koordinat_dari_url_place(): void
    {
        $titik = MapsPoint::fromUrl(
            'https://www.google.com/maps/place/Citraland+Surabaya/@-7.28848,112.6331921,17z/data=!3m1!4b1'
        );

        $this->assertNotNull($titik);
        $this->assertSame(-7.28848, $titik['latitude']);
        $this->assertSame(112.6331921, $titik['longitude']);
    }

    public function test_membaca_domain_google_co_id(): void
    {
        $titik = MapsPoint::fromUrl(
            'https://www.google.co.id/maps/place/Giri+Palma+Mebel/@-7.9553004,112.5873766,17z'
        );

        $this->assertSame(-7.9553004, $titik['latitude']);
        $this->assertSame(112.5873766, $titik['longitude']);
    }

    public function test_zoom_tidak_tertukar_jadi_bujur(): void
    {
        $titik = MapsPoint::fromUrl('https://maps.google.com/maps/@-7.1,112.2,21z');

        $this->assertSame(112.2, $titik['longitude']);
    }

    public function test_koordinat_bulat_tanpa_desimal_diterima(): void
    {
        $titik = MapsPoint::fromUrl('https://www.google.com/maps/@-7,112,17z');

        $this->assertSame(-7.0, $titik['latitude']);
        $this->assertSame(112.0, $titik['longitude']);
    }

    public function test_nama_tempat_berisi_at_tidak_mengecoh(): void
    {
        $titik = MapsPoint::fromUrl(
            'https://www.google.com/maps/place/Kopi+@+Rumah/@-7.3,112.7,17z'
        );

        $this->assertSame(-7.3, $titik['latitude']);
    }

    /**
     * Review Focus 1: format share lain yang menaruh titiknya di !3d/!4d.
     * Tidak dibaca — dan harus dilaporkan sebagai tidak ada titik, bukan
     * setengah titik atau galat.
     */
    public function test_url_tanpa_at_latlng_dianggap_tidak_ada_titik(): void
    {
        $this->assertNull(MapsPoint::fromUrl(
            'https://www.google.com/maps/place/Toko/data=!3m1!4b1!3d-7.9!4d112.5'
        ));
    }

    public function test_url_pencarian_tanpa_koordinat_dianggap_tidak_ada_titik(): void
    {
        $this->assertNull(MapsPoint::fromUrl(
            'https://www.google.com/maps/search/Jalan+Mojo+3'
        ));
    }

    /** Review Focus 2: 0,0 lolos rentang tapi menunjuk Teluk Guinea. */
    public function test_nol_nol_ditolak(): void
    {
        $this->assertNull(MapsPoint::fromUrl('https://www.google.com/maps/@0,0,17z'));
    }

    public function test_lintang_di_luar_rentang_ditolak(): void
    {
        $this->assertNull(MapsPoint::fromUrl('https://www.google.com/maps/@-91.5,112.5,17z'));
    }

    public function test_bujur_di_luar_rentang_ditolak(): void
    {
        $this->assertNull(MapsPoint::fromUrl('https://www.google.com/maps/@-7.5,181.2,17z'));
    }

    public function test_bukan_url_dan_kosong_dianggap_tidak_ada_titik(): void
    {
        $this->assertNull(MapsPoint::fromUrl('depan lapangan, pagar hijau'));
        $this->assertNull(MapsPoint::fromUrl(''));
        $this->assertNull(MapsPoint::fromUrl(null));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=MapsPointTest
```

Diharapkan: GAGAL dengan `Class "App\Support\MapsPoint" not found`.

- [ ] **Step 3: Tulis implementasi minimal**

Buat `app/Support/MapsPoint.php`:

```php
<?php

namespace App\Support;

/**
 * Membaca koordinat dari URL Google Maps.
 *
 * Satu-satunya bentuk yang dibaca adalah `@lintang,bujur` — bagian yang dipakai
 * Google sebagai pusat peta, dan yang nyata ada di 3.913 dari 3.916 baris
 * `customers.maps` di produksi. Format share lain yang menyimpan titiknya di
 * `!3d`/`!4d` sengaja TIDAK dibaca: menebak dari dua sumber yang kadang
 * berbeda isinya lebih buruk daripada mengaku tidak tahu, karena titik yang
 * salah di peta kurir terlihat sah.
 *
 * Mengembalikan null berarti "tidak ada titik di URL ini", bukan galat. Yang
 * memanggil menampilkan "Titik belum ada", dan itu keadaan yang sah untuk 748
 * pelanggan yang memang belum pernah punya titik.
 */
class MapsPoint
{
    public static function fromUrl(?string $url): ?array
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        // Digit wajib menempel sesudah `@` (boleh didahului minus), supaya nama
        // tempat yang memuat "@" tidak ikut tertangkap.
        if (! preg_match('/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', $url, $cocok)) {
            return null;
        }

        $lintang = (float) $cocok[1];
        $bujur = (float) $cocok[2];

        if ($lintang < -90 || $lintang > 90 || $bujur < -180 || $bujur > 180) {
            return null;
        }

        // 0,0 lolos rentang tapi jatuh di Teluk Guinea. Tidak ada pelanggan
        // Shoesfast di sana; yang ada hanya URL rusak atau koordinat yang
        // gagal terisi. Pin palsu di peta kurir lebih buruk daripada kosong.
        if ($lintang === 0.0 && $bujur === 0.0) {
            return null;
        }

        return ['latitude' => $lintang, 'longitude' => $bujur];
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=MapsPointTest
```

Diharapkan: LULUS, 12 test.

- [ ] **Step 5: Format dan commit**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
./vendor/bin/pint app/Support/MapsPoint.php tests/Unit/MapsPointTest.php
git add app/Support/MapsPoint.php tests/Unit/MapsPointTest.php
git commit -m "feat: parser koordinat dari URL Google Maps"
```

---

### Task 2: Koordinat jadi turunan `maps` di model Customer

**Files:**
- Modify: `app/Models/Customer.php` (di dalam `boot()`, baris 86-93)
- Test: `tests/Feature/TitikPelangganTest.php`

**Interfaces:**
- Consumes: `App\Support\MapsPoint::fromUrl()` dari Task 1.
- Produces: jaminan bahwa setiap `Customer::create()`/`update()` yang menyentuh `maps` meninggalkan `latitude`/`longitude` konsisten. Task 3 dan Task 4 bersandar pada ini.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/TitikPelangganTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use Tests\Feature\Customer\CreatesCustomerSchema;
use Tests\TestCase;

/**
 * Aturan satu arah: `maps` yang dipegang manusia, koordinat turunannya.
 *
 * Diuji di sini karena aturannya tinggal di hook `saving` model — bukan di
 * controller — supaya kelima jalur tulis yang ada hari ini (admin store, admin
 * update, endpoint titik, portal profil, portal register) ikut benar tanpa
 * harus diingat satu per satu.
 */
class TitikPelangganTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
    }

    private function buatPelanggan(array $atribut = []): Customer
    {
        return Customer::create(array_merge([
            'projects_id' => 1,
            'name' => 'Pelanggan Uji',
            'phone' => '81234567890',
            'is_deleted' => 0,
        ], $atribut));
    }

    public function test_menyimpan_maps_mengisi_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/place/Citraland/@-7.28848,112.6331921,17z',
        ]);

        $this->assertSame(-7.28848, (float) $pelanggan->latitude);
        $this->assertSame(112.6331921, (float) $pelanggan->longitude);
    }

    public function test_mengganti_maps_menurunkan_koordinat_baru(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['maps' => 'https://www.google.com/maps/@-8.2,113.2,17z']);

        $this->assertSame(-8.2, (float) $pelanggan->fresh()->latitude);
        $this->assertSame(113.2, (float) $pelanggan->fresh()->longitude);
    }

    public function test_mengosongkan_maps_mengosongkan_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['maps' => null]);

        $this->assertNull($pelanggan->fresh()->latitude);
        $this->assertNull($pelanggan->fresh()->longitude);
    }

    public function test_maps_tanpa_koordinat_mengosongkan_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['maps' => 'https://www.google.com/maps/search/Jalan+Mojo']);

        $this->assertNull($pelanggan->fresh()->latitude);
    }

    public function test_koordinat_tanpa_maps_dipakai_apa_adanya(): void
    {
        // Jalur portal pelanggan: Customer/ProfileController menerima
        // latitude/longitude langsung. Hook tidak boleh membuangnya.
        $pelanggan = $this->buatPelanggan([
            'latitude' => -7.5,
            'longitude' => 112.5,
        ]);

        $this->assertSame(-7.5, (float) $pelanggan->latitude);
        $this->assertSame(112.5, (float) $pelanggan->longitude);
    }

    public function test_maps_menang_atas_koordinat_yang_dikirim_bersamaan(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
            'latitude' => -1.1,
            'longitude' => 101.1,
        ]);

        $this->assertSame(-7.28848, (float) $pelanggan->latitude);
        $this->assertSame(112.6331921, (float) $pelanggan->longitude);
    }

    /**
     * Review Focus 5: ketiga aturan di spec bertabrakan kalau `maps`
     * dikosongkan sementara koordinat dikirim eksplisit dalam satu simpanan.
     * Resolusinya: URL yang bisa diparse selalu menang; kalau URL kosong atau
     * tidak memuat titik, angka eksplisit dihormati.
     */
    public function test_maps_dikosongkan_bersama_koordinat_eksplisit_menghormati_angkanya(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update([
            'maps' => null,
            'latitude' => -9.9,
            'longitude' => 119.9,
        ]);

        $this->assertSame(-9.9, (float) $pelanggan->fresh()->latitude);
        $this->assertSame(119.9, (float) $pelanggan->fresh()->longitude);
    }

    public function test_menyimpan_kolom_lain_tidak_menyentuh_koordinat(): void
    {
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $pelanggan->update(['name' => 'Nama Baru']);

        $this->assertSame(-7.1, (float) $pelanggan->fresh()->latitude);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=TitikPelangganTest
```

Diharapkan: GAGAL pada `test_menyimpan_maps_mengisi_koordinat` — `assertSame(-7.28848, 0.0)` atau koordinat null, karena belum ada yang mengisinya.

- [ ] **Step 3: Tambahkan hook di model**

Di `app/Models/Customer.php`, tambahkan `use App\Support\MapsPoint;` di blok `use` paling atas, lalu ganti isi `boot()` (baris 86-93) menjadi:

```php
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
        });

        /*
         * `maps` yang dipegang manusia; `latitude`/`longitude` turunannya.
         *
         * Diletakkan di model, bukan di controller, karena ada lima jalur tulis
         * ke kolom ini hari ini — admin store, admin update, endpoint titik,
         * portal profil, portal register — dan jalur keenam nanti akan ikut
         * benar tanpa ada yang perlu mengingatnya.
         *
         * Urutan menangnya: URL yang bisa diparse selalu menang. Kalau URL
         * kosong atau tidak memuat titik, angka yang dikirim eksplisit pada
         * simpanan yang sama dihormati — itu jalur portal pelanggan, yang
         * mengirim koordinat tanpa bersandar pada URL. Kalau tidak ada
         * keduanya, koordinat dikosongkan: titik basi yang menunjuk rumah lama
         * terlihat sah dan justru menyesatkan kurir.
         */
        static::saving(function (self $pelanggan) {
            if (! $pelanggan->isDirty('maps')) {
                return;
            }

            $titik = MapsPoint::fromUrl($pelanggan->maps);

            if ($titik !== null) {
                $pelanggan->latitude = $titik['latitude'];
                $pelanggan->longitude = $titik['longitude'];

                return;
            }

            if (! $pelanggan->isDirty('latitude') && ! $pelanggan->isDirty('longitude')) {
                $pelanggan->latitude = null;
                $pelanggan->longitude = null;
            }
        });
    }
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=TitikPelangganTest
```

Diharapkan: LULUS, 8 test.

- [ ] **Step 5: Jalankan seluruh suite — hook ini menyentuh semua jalur tulis pelanggan**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
composer test
```

Diharapkan: 352 test lama tetap lulus, ditambah yang baru. Kalau ada test portal pelanggan yang jadi merah, itu temuan nyata — baca harapannya sebelum mengubah apa pun.

- [ ] **Step 6: Format dan commit**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
./vendor/bin/pint app/Models/Customer.php tests/Feature/TitikPelangganTest.php
git add app/Models/Customer.php tests/Feature/TitikPelangganTest.php
git commit -m "feat: koordinat pelanggan diturunkan dari URL maps"
```

---

### Task 3: Endpoint `PUT /api/customers/{id}/point`

**Files:**
- Modify: `app/Http/Controllers/Api/CustomerController.php` (method baru sesudah `update()`, yang berakhir di baris ≈588)
- Modify: `routes/api.php` (di dalam grup `role:Admin Super,Admin,Admin Crm,Admin Sosmed`, sebelum `Route::apiResource('customers', ...)` baris 345)
- Test: `tests/Feature/TitikPelangganTest.php` (tambahkan ke berkas dari Task 2)

**Interfaces:**
- Consumes: hook model dari Task 2.
- Produces: `PUT /api/customers/{id}/point`, badan `{"maps": string|null}`, balasan `{"data": {"id": int, "maps": string|null, "latitude": float|null, "longitude": float|null}}`. Task 7 memanggilnya.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/TitikPelangganTest.php` — dan tambahkan dua `use` di atas berkasnya: `use App\Models\Role;`, `use App\Models\User;`, `use Laravel\Sanctum\Sanctum;`:

```php
    private function masukSebagaiAdmin(): void
    {
        $user = new User(['name' => 'Admin Uji', 'projects_id' => 1]);
        $user->id = 1;
        $user->setRelation('role', new Role(['name' => 'Admin']));

        Sanctum::actingAs($user);
    }

    public function test_endpoint_titik_menyimpan_maps_dan_membalas_koordinatnya(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan();

        $this->putJson("/api/customers/{$pelanggan->id}/point", [
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
        ])
            ->assertOk()
            ->assertJsonPath('data.latitude', -7.28848)
            ->assertJsonPath('data.longitude', 112.6331921);

        $this->assertSame(-7.28848, (float) $pelanggan->fresh()->latitude);
    }

    public function test_endpoint_titik_tidak_menyentuh_kolom_lain(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan([
            'name' => 'Nama Asli',
            'phone' => '81200000000',
            'address' => 'Alamat Asli',
        ]);

        $this->putJson("/api/customers/{$pelanggan->id}/point", [
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ])->assertOk();

        $segar = $pelanggan->fresh();
        $this->assertSame('Nama Asli', $segar->name);
        $this->assertSame('81200000000', $segar->phone);
        $this->assertSame('Alamat Asli', $segar->address);
    }

    /** Review Focus 3: admin yang sadar titiknya salah butuh jalan membatalkannya. */
    public function test_endpoint_titik_menerima_null_untuk_menghapus_titik(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ]);

        $this->putJson("/api/customers/{$pelanggan->id}/point", ['maps' => null])
            ->assertOk()
            ->assertJsonPath('data.latitude', null);

        $this->assertNull($pelanggan->fresh()->maps);
    }

    public function test_endpoint_titik_menolak_maps_bukan_string(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan();

        $this->putJson("/api/customers/{$pelanggan->id}/point", ['maps' => ['a' => 1]])
            ->assertStatus(422);
    }

    /** Review Focus 4: pelanggan yang sudah dihapus lunak tidak bisa disunting. */
    public function test_endpoint_titik_404_untuk_pelanggan_terhapus(): void
    {
        $this->masukSebagaiAdmin();
        $pelanggan = $this->buatPelanggan();
        $pelanggan->update(['is_deleted' => 1]);

        $this->putJson("/api/customers/{$pelanggan->id}/point", [
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
        ])->assertStatus(404);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=TitikPelangganTest
```

Diharapkan: GAGAL dengan 404/405 pada rute yang belum ada.

- [ ] **Step 3: Tambahkan method di controller**

Di `app/Http/Controllers/Api/CustomerController.php`, sesudah `update()` berakhir (baris ≈588), tambahkan:

```php
    /**
     * PUT /api/customers/{id}/point
     *
     * Menyimpan HANYA titik peta pelanggan, dipanggil dari halaman tempat tugas
     * kurir dibuat ketika admin menaruh pin di peta.
     *
     * Endpoint terpisah, bukan `update()` yang sudah ada, karena endpoint itu
     * menuntut `name` dan `phone`. Melonggarkan keduanya jadi `sometimes`
     * berarti melonggarkan validasi untuk SEMUA pemanggil demi satu layar —
     * sesudah itu PUT yang kelupaan mengirim nama akan diam-diam mempertahankan
     * nama lama. Mengakalinya dengan GET-dulu-lalu-PUT-semuanya juga ditolak:
     * itu baca-ubah-tulis di atas 19 kolom, dan sekali bentuk GET berbeda dari
     * bentuk PUT, `project_ids` atau `member_code` ikut tertimpa tanpa galat.
     *
     * Koordinatnya tidak ditulis di sini — hook `saving` di model Customer yang
     * menurunkannya dari URL, sama seperti jalur tulis lainnya.
     */
    public function updatePoint(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'maps' => ['nullable', 'string'],
        ]);

        // Cakupan data sama dengan update() dan destroy() di controller ini.
        $customer = Customer::where('projects_id', 1)->findOrFail($id);

        $customer->update([
            'maps' => $validated['maps'] ?? null,
            'modified_by' => auth()->id(),
        ]);

        $customer->refresh();

        return response()->json([
            'message' => 'Titik pelanggan tersimpan.',
            'data' => [
                'id' => $customer->id,
                'maps' => $customer->maps,
                'latitude' => $customer->latitude === null ? null : (float) $customer->latitude,
                'longitude' => $customer->longitude === null ? null : (float) $customer->longitude,
            ],
        ]);
    }
```

Kalau `JsonResponse` belum di-import di berkas itu, tambahkan `use Illuminate\Http\JsonResponse;`.

- [ ] **Step 4: Daftarkan rutenya**

Di `routes/api.php`, di dalam grup `role:Admin Super,Admin,Admin Crm,Admin Sosmed`, tepat sebelum `Route::apiResource('customers', CustomerController::class);` (baris 345):

```php
        // Sebelum apiResource, mengikuti aturan repo ini. Hanya menyimpan titik
        // peta — dipanggil dari halaman pembuatan tugas kurir, bukan dari form
        // pelanggan.
        Route::put('customers/{id}/point', [CustomerController::class, 'updatePoint']);
```

- [ ] **Step 5: Jalankan test, pastikan LULUS**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=TitikPelangganTest
```

Diharapkan: LULUS, 13 test.

- [ ] **Step 6: Format dan commit**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
./vendor/bin/pint app/Http/Controllers/Api/CustomerController.php routes/api.php tests/Feature/TitikPelangganTest.php
git add app/Http/Controllers/Api/CustomerController.php routes/api.php tests/Feature/TitikPelangganTest.php
git commit -m "feat: endpoint simpan titik pelanggan"
```

---

### Task 4: `customer_id` dan koordinat di payload pembuat tugas

**Files:**
- Modify: `app/Http/Controllers/Api/SendController.php` — empat tempat: `pickupWaitingList()` (≈517), `getAvailablePickupOrders()` (≈548), `deliveryWaitingList()` (≈624), `getAvailableDeliveryItems()` (≈709)
- Test: `tests/Feature/TitikPelangganTest.php`

**Interfaces:**
- Consumes: koordinat yang sudah konsisten dari Task 2.
- Produces: tiap baris payload memuat `customer_id: int`, `customer_latitude: float|null`, `customer_longitude: float|null`. Task 8 membacanya.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/TitikPelangganTest.php`:

```php
    public function test_daftar_pesanan_siap_jemput_memuat_id_dan_koordinat_pelanggan(): void
    {
        $this->masukSebagaiAdmin();

        $pelanggan = $this->buatPelanggan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
        ]);

        \App\Models\Order::create([
            'projects_id' => 1,
            'customers_id' => $pelanggan->id,
            'users_id' => 1,
            'code' => 'UJI-001',
            'date' => time(),
            'status' => 0,
            'is_deleted' => 0,
        ]);

        $this->getJson('/api/sends/available-pickup-orders')
            ->assertOk()
            ->assertJsonPath('0.customer_id', $pelanggan->id)
            ->assertJsonPath('0.customer_latitude', -7.28848)
            ->assertJsonPath('0.customer_longitude', 112.6331921);
    }

    public function test_pelanggan_tanpa_titik_mengirim_koordinat_null(): void
    {
        $this->masukSebagaiAdmin();

        $pelanggan = $this->buatPelanggan();

        \App\Models\Order::create([
            'projects_id' => 1,
            'customers_id' => $pelanggan->id,
            'users_id' => 1,
            'code' => 'UJI-002',
            'date' => time(),
            'status' => 0,
            'is_deleted' => 0,
        ]);

        $this->getJson('/api/sends/available-pickup-orders')
            ->assertOk()
            ->assertJsonPath('0.customer_latitude', null)
            ->assertJsonPath('0.customer_longitude', null);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=TitikPelangganTest
```

Diharapkan: GAGAL — `customer_id` tidak ada di balasan.

- [ ] **Step 3: Tambahkan ketiga field di empat payload**

Di keempat method, di dalam `transform(...)`, tambahkan tepat sesudah baris `'customer_maps' => ...`:

```php
                // `customer_id` dipakai halaman admin untuk menyimpan titik
                // yang baru ditaruh. Koordinat dikirim sebagai ANGKA — klien
                // tidak boleh mem-parse URL sendiri, karena parser yang sama
                // lalu hidup di tiga tempat dan menyimpang pelan-pelan.
                'customer_id' => $order->customer->id,
                'customer_latitude' => $order->customer->latitude === null
                    ? null
                    : (float) $order->customer->latitude,
                'customer_longitude' => $order->customer->longitude === null
                    ? null
                    : (float) $order->customer->longitude,
```

Perhatikan nama variabelnya berbeda per method. Di `pickupWaitingList()` dan `getAvailablePickupOrders()` sumbernya `$order->customer`. Di `deliveryWaitingList()` dan `getAvailableDeliveryItems()` sumbernya `$item->order->customer`, jadi tulis `$item->order->customer->id` dan seterusnya. Pakai `?->` kalau baris `customer_maps` di method itu sudah memakai `?? null` — contohnya baris ≈624 memakai `$item->order->customer->maps ?? null`, jadi di situ tulis `$item->order->customer?->id`.

- [ ] **Step 4: Jalankan test, pastikan LULUS**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=TitikPelangganTest
```

Diharapkan: LULUS, 15 test.

- [ ] **Step 5: Jalankan seluruh suite**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
composer test
```

Diharapkan: semuanya lulus. `MobileKontrakTest` menjaga bentuk balasan untuk aplikasi Flutter — kalau ia merah, baca harapannya, jangan ubah testnya.

- [ ] **Step 6: Format dan commit**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
./vendor/bin/pint app/Http/Controllers/Api/SendController.php tests/Feature/TitikPelangganTest.php
git add app/Http/Controllers/Api/SendController.php tests/Feature/TitikPelangganTest.php
git commit -m "feat: payload tugas kurir memuat id dan titik pelanggan"
```

---

### Task 5: Migrasi backfill 3.913 koordinat

**Files:**
- Create: `database/migrations/2026_10_12_000001_backfill_customer_point.php`
- Modify: `docs/sql-perubahan-db.md`
- Test: `tests/Feature/BackfillTitikPelangganTest.php`

**Interfaces:**
- Consumes: `App\Support\MapsPoint::fromUrl()` dari Task 1.
- Produces: tidak ada kode yang dipanggil tugas lain.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/BackfillTitikPelangganTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\MapsPoint;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Customer\CreatesCustomerSchema;
use Tests\TestCase;

/**
 * Backfill memanen koordinat dari URL yang sudah tersimpan.
 *
 * Yang diuji bukan jumlah barisnya di produksi (3.913), tapi dua sifat yang
 * membuatnya aman dijalankan di server: hanya menyentuh baris yang koordinatnya
 * masih kosong, dan hasilnya sama kalau dijalankan dua kali.
 */
class BackfillTitikPelangganTest extends TestCase
{
    use CreatesCustomerSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCustomerSchema();
    }

    /** Potongan yang sama dengan isi migrasi, dipanggil langsung. */
    private function jalankanBackfill(): int
    {
        $terisi = 0;

        Customer::withoutGlobalScope('notDeleted')
            ->whereNull('latitude')
            ->whereNotNull('maps')
            ->where('maps', '<>', '')
            ->select(['id', 'maps'])
            ->chunkById(500, function ($baris) use (&$terisi) {
                foreach ($baris as $pelanggan) {
                    $titik = MapsPoint::fromUrl($pelanggan->maps);

                    if ($titik === null) {
                        continue;
                    }

                    DB::table('customers')
                        ->where('id', $pelanggan->id)
                        ->update([
                            'latitude' => $titik['latitude'],
                            'longitude' => $titik['longitude'],
                        ]);

                    $terisi++;
                }
            });

        return $terisi;
    }

    private function sisipkan(array $atribut): int
    {
        return DB::table('customers')->insertGetId(array_merge([
            'projects_id' => 1,
            'name' => 'Pelanggan',
            'phone' => '81200000000',
            'is_deleted' => 0,
        ], $atribut));
    }

    public function test_mengisi_baris_yang_koordinatnya_kosong(): void
    {
        $id = $this->sisipkan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
        ]);

        $this->assertSame(1, $this->jalankanBackfill());

        $baris = DB::table('customers')->find($id);
        $this->assertSame(-7.28848, (float) $baris->latitude);
        $this->assertSame(112.6331921, (float) $baris->longitude);
    }

    public function test_tidak_menimpa_titik_yang_sudah_ada(): void
    {
        $id = $this->sisipkan([
            'maps' => 'https://www.google.com/maps/@-7.28848,112.6331921,17z',
            'latitude' => -1.5,
            'longitude' => 101.5,
        ]);

        $this->assertSame(0, $this->jalankanBackfill());
        $this->assertSame(-1.5, (float) DB::table('customers')->find($id)->latitude);
    }

    public function test_melewati_url_tanpa_koordinat_tanpa_menggagalkan_sisanya(): void
    {
        $rusak = $this->sisipkan(['maps' => 'depan lapangan, pagar hijau']);
        $benar = $this->sisipkan(['maps' => 'https://www.google.com/maps/@-7.1,112.1,17z']);

        $this->assertSame(1, $this->jalankanBackfill());
        $this->assertNull(DB::table('customers')->find($rusak)->latitude);
        $this->assertSame(-7.1, (float) DB::table('customers')->find($benar)->latitude);
    }

    /** Review Focus 4: baris terhapus lunak ikut dipanen, tapi tidak dihitung gagal. */
    public function test_baris_terhapus_lunak_ikut_dipanen(): void
    {
        $id = $this->sisipkan([
            'maps' => 'https://www.google.com/maps/@-7.1,112.1,17z',
            'is_deleted' => 1,
        ]);

        $this->jalankanBackfill();

        $this->assertSame(-7.1, (float) DB::table('customers')->find($id)->latitude);
    }

    public function test_dijalankan_dua_kali_tidak_mengubah_apa_pun(): void
    {
        $this->sisipkan(['maps' => 'https://www.google.com/maps/@-7.1,112.1,17z']);

        $this->assertSame(1, $this->jalankanBackfill());
        $this->assertSame(0, $this->jalankanBackfill());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=BackfillTitikPelangganTest
```

Diharapkan: GAGAL. (Kalau Task 1 sudah ada, kegagalannya di harapan jumlah baris, bukan class not found — baca pesannya dan pastikan sebabnya memang belum ada backfill, bukan salah tulis test.)

- [ ] **Step 3: Tulis migrasinya**

Buat `database/migrations/2026_10_12_000001_backfill_customer_point.php`:

```php
<?php

use App\Models\Customer;
use App\Support\MapsPoint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memanen koordinat yang selama ini terkubur di dalam URL `customers.maps`.
 *
 * Di produksi 3.913 dari 3.916 URL memuat `@lintang,bujur`, sementara kolom
 * `latitude`/`longitude` hanya terisi pada 1 dari 4.664 baris — tidak ada satu
 * pun jalur tulis yang pernah mengisinya. Data titiknya sudah dimiliki
 * Shoesfast; yang belum ada hanya pemindahannya ke bentuk yang bisa dipakai.
 *
 * Hanya menyentuh baris yang koordinatnya NULL, jadi aman dijalankan berulang
 * dan tidak pernah menimpa titik yang sudah benar. Baris terhapus lunak ikut
 * dipanen: pelanggan bisa dipulihkan, dan titik yang hilang saat pemulihan
 * adalah kejutan yang tidak perlu.
 *
 * Memakai PHP, bukan regex SQL, supaya aturan penerimaan nilainya sama persis
 * dengan jalur tulis biasa — termasuk penolakan di luar rentang dan penolakan
 * 0,0 (lihat App\Support\MapsPoint).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')
            || ! Schema::hasColumn('customers', 'latitude')
            || ! Schema::hasColumn('customers', 'maps')) {
            return;
        }

        Customer::withoutGlobalScope('notDeleted')
            ->whereNull('latitude')
            ->whereNotNull('maps')
            ->where('maps', '<>', '')
            ->select(['id', 'maps'])
            ->chunkById(500, function ($baris) {
                foreach ($baris as $pelanggan) {
                    $titik = MapsPoint::fromUrl($pelanggan->maps);

                    if ($titik === null) {
                        continue;
                    }

                    // DB::table, bukan $pelanggan->save(): ini menulis kolom
                    // turunan secara langsung dan tidak boleh menyentuh
                    // `modified_at`/`modified_by`. Backfill bukan suntingan
                    // manusia, dan riwayat tidak boleh berbohong soal itu.
                    DB::table('customers')
                        ->where('id', $pelanggan->id)
                        ->update([
                            'latitude' => $titik['latitude'],
                            'longitude' => $titik['longitude'],
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Tidak dibalikkan. Koordinat hasil panen tidak bisa dibedakan dari
        // yang ditaruh manusia sesudahnya, jadi mengosongkannya kembali berisiko
        // membuang titik yang benar. `maps` tetap utuh, panen bisa diulang.
    }
};
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan test --filter=BackfillTitikPelangganTest
```

Diharapkan: LULUS, 5 test.

- [ ] **Step 5: Jalankan migrasinya di DB lokal dan periksa hasilnya**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
php artisan migrate
mysql -uroot shoesfast -e "select count(*) total, sum(latitude is not null) ada_titik from customers where is_deleted=0;"
```

Diharapkan: `ada_titik` naik dari 1 ke sekitar 3.914.

- [ ] **Step 6: Tulis SQL padanannya**

Tambahkan ke `docs/sql-perubahan-db.md`, mengikuti format entri yang sudah ada di berkas itu (baca dulu bagian atasnya): judul bertanggal, satu paragraf sebab, lalu SQL-nya.

```sql
-- 2026-10-12 — panen koordinat dari URL maps pelanggan
-- Hanya baris yang koordinatnya masih NULL. Aman dijalankan berulang.
-- Catatan: SQL ini TIDAK menolak 0,0 seperti versi PHP-nya. Jalankan migrasi
-- Laravel kalau bisa; pakai SQL ini hanya kalau artisan tidak tersedia, lalu
-- bersihkan sisanya dengan UPDATE kedua di bawah.
UPDATE customers
SET
  latitude  = CAST(REGEXP_SUBSTR(maps, '(?<=@)-?[0-9]+\\.?[0-9]*') AS DECIMAL(10,8)),
  longitude = CAST(REGEXP_SUBSTR(maps, '(?<=@-?[0-9]{1,3}\\.?[0-9]*,)-?[0-9]+\\.?[0-9]*') AS DECIMAL(11,8))
WHERE latitude IS NULL
  AND maps IS NOT NULL AND maps <> ''
  AND maps REGEXP '@-?[0-9]+\\.?[0-9]*,-?[0-9]+\\.?[0-9]*';

-- Buang hasil yang tidak masuk akal, menyamakan perilakunya dengan MapsPoint.
UPDATE customers
SET latitude = NULL, longitude = NULL
WHERE (latitude = 0 AND longitude = 0)
   OR latitude  NOT BETWEEN -90 AND 90
   OR longitude NOT BETWEEN -180 AND 180;

-- Periksa hasilnya.
SELECT COUNT(*) total, SUM(latitude IS NOT NULL) ada_titik
FROM customers WHERE is_deleted = 0;
```

- [ ] **Step 7: Format dan commit**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
./vendor/bin/pint database/migrations/2026_10_12_000001_backfill_customer_point.php tests/Feature/BackfillTitikPelangganTest.php
git add database/migrations/2026_10_12_000001_backfill_customer_point.php tests/Feature/BackfillTitikPelangganTest.php docs/sql-perubahan-db.md
git commit -m "feat: panen koordinat dari URL maps yang sudah ada"
```

---

### Task 6: Prop `readOnly` di MapPicker

**Files:**
- Modify: `shoesfast-app-fe/src/components/map-picker.tsx` (type di baris 19-22, `LocationMarker` di baris ≈25-31, body render di baris ≈128-165)

**Interfaces:**
- Consumes: tidak ada.
- Produces: `<MapPicker value={string} onChange={(mapsUrl, lat?, lng?) => void} readOnly?={boolean} />`. Task 7 memakainya.

Semua perintah tugas ini dijalankan dari `shoesfast-app-fe`.

- [ ] **Step 1: Tambahkan prop di type dan teruskan ke marker**

Ganti `MapPickerProps` (baris 19-22) jadi:

```tsx
type MapPickerProps = {
  value: string // Google Maps URL or empty
  onChange: (mapsUrl: string, lat?: number, lng?: number) => void
  /**
   * Mode lihat saja: klik peta tidak memindahkan pin, kolom URL manual dan
   * tombol "pakai lokasi saya" disembunyikan. Dipakai dialog titik pelanggan,
   * yang membuka peta untuk memastikan — bukan untuk menyunting — sampai admin
   * menekan "Ubah titik".
   */
  readOnly?: boolean
}
```

Ganti `LocationMarker` (baris ≈25-31) jadi:

```tsx
function LocationMarker({
  position,
  setPosition,
  readOnly,
}: {
  position: L.LatLng | null
  setPosition: (p: L.LatLng) => void
  readOnly?: boolean
}) {
  useMapEvents({
    click(e) {
      if (readOnly) return
      setPosition(e.latlng)
    },
  })

  return position === null ? null : <Marker position={position} />
}
```

`useMapEvents` sudah diimpor di baris 4 berkas itu — tidak ada import baru yang perlu ditambahkan.

- [ ] **Step 2: Sembunyikan kontrol sunting saat readOnly**

Di `export function MapPicker({ value, onChange }: MapPickerProps)` (baris 34) tambahkan `readOnly` ke destructuring. Lalu bungkus blok kontrol di body render (baris ≈130-140, `<div className="flex gap-2">` yang memuat `<Input>` dan tombol `getCurrentLocation`):

```tsx
      {!readOnly && (
        <div className="flex gap-2">
          <Input
            placeholder="Paste Google Maps URL atau klik di peta"
            value={mapsUrl}
            onChange={handleManualInput}
            className="flex-1"
          />
          <Button type="button" onClick={getCurrentLocation} variant="outline" size="icon" title="Gunakan Lokasi Saya">
            <MapPin className="h-4 w-4" />
          </Button>
        </div>
      )}
```

Dan teruskan propnya ke marker:

```tsx
          <LocationMarker position={position} setPosition={setPosition} readOnly={readOnly} />
```

- [ ] **Step 3: Gerbang — lint dan build**

```bash
npx eslint src/components/map-picker.tsx
npm run build
```

Diharapkan: eslint tanpa keluaran, build `✓ Compiled successfully`.

- [ ] **Step 4: Commit**

```bash
git add src/components/map-picker.tsx
git commit -m "feat: MapPicker punya mode lihat saja"
```

---

### Task 7: Komponen `<CustomerPointButton>`

**Files:**
- Create: `shoesfast-app-fe/src/components/customer-point.tsx`

**Interfaces:**
- Consumes: `<MapPicker readOnly>` dari Task 6; `PUT /api/customers/{id}/point` dari Task 3.
- Produces:
  ```tsx
  <CustomerPointButton
    customerId={number}
    customerName={string | null}
    address={string | null}
    latitude={number | null}
    longitude={number | null}
    onSaved={(titik: { maps: string | null; latitude: number | null; longitude: number | null }) => void}
  />
  ```
  Task 8 memakainya.

- [ ] **Step 1: Tulis komponennya**

Buat `src/components/customer-point.tsx`:

```tsx
"use client"

import { useState } from "react"
import dynamic from "next/dynamic"
import { MapPin, MapPinOff, Loader2, ExternalLink } from "lucide-react"
import { toast } from "sonner"
import { api } from "@/lib/api"
import { titleCase } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"

// Leaflet hanya dimuat saat dialognya dibuka. Tiga halaman yang memakai
// komponen ini adalah daftar sampai 50 baris; menarik peta pada saat halaman
// dibuka berarti membayar berat Leaflet untuk sesuatu yang biasanya tidak
// pernah diklik.
const MapPicker = dynamic(
  () => import("@/components/map-picker").then((m) => m.MapPicker),
  {
    ssr: false,
    loading: () => (
      <div className="flex h-[400px] items-center justify-center rounded-lg bg-muted">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    ),
  }
)

type Titik = {
  maps: string | null
  latitude: number | null
  longitude: number | null
}

type CustomerPointButtonProps = {
  customerId: number
  customerName: string | null
  address: string | null
  latitude: number | null
  longitude: number | null
  onSaved: (titik: Titik) => void
}

export function CustomerPointButton({
  customerId,
  customerName,
  address,
  latitude,
  longitude,
  onSaved,
}: CustomerPointButtonProps) {
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  // URL hasil pin yang baru ditaruh, belum disimpan.
  const [draftMaps, setDraftMaps] = useState<string | null>(null)

  const adaTitik = latitude !== null && longitude !== null
  const mapsUrl = adaTitik
    ? `https://www.google.co.id/maps/place/@${latitude},${longitude},17z`
    : ""

  function bukaDialog(e: React.MouseEvent) {
    // Baris daftarnya sendiri bisa diklik untuk memilih tugas; membuka peta
    // tidak boleh ikut mencentang baris itu.
    e.stopPropagation()
    setDraftMaps(null)
    setEditing(!adaTitik)
    setOpen(true)
  }

  async function simpan() {
    if (draftMaps === null) {
      setEditing(false)
      return
    }

    setSaving(true)
    try {
      const res = await api.put<{ data: Titik }>(
        `/api/customers/${customerId}/point`,
        { maps: draftMaps }
      )
      onSaved(res.data)
      toast.success("Titik pelanggan tersimpan.")
      setOpen(false)
      setEditing(false)
      setDraftMaps(null)
    } catch {
      // Dialog sengaja dibiarkan terbuka: pin yang baru ditaruh admin tidak
      // boleh hilang hanya karena jaringan sedang buruk.
      toast.error("Gagal menyimpan titik. Coba lagi.")
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        onClick={bukaDialog}
        className="h-7 gap-1 px-2 text-xs"
        title={adaTitik ? "Lihat titik di peta" : "Taruh titik di peta"}
      >
        {adaTitik ? (
          <>
            <MapPin className="h-3.5 w-3.5" />
            Titik
          </>
        ) : (
          <>
            <MapPinOff className="h-3.5 w-3.5 text-muted-foreground" />
            <span className="text-muted-foreground">Titik belum ada</span>
          </>
        )}
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-[560px]">
          <DialogHeader>
            <DialogTitle>
              {editing ? "Taruh titik" : "Titik"} — {titleCase(customerName) || "Pelanggan"}
            </DialogTitle>
            <DialogDescription>
              {address || "Alamat tidak tercatat."}
            </DialogDescription>
          </DialogHeader>

          <MapPicker
            value={draftMaps ?? mapsUrl}
            readOnly={!editing}
            onChange={(url) => setDraftMaps(url)}
          />

          <DialogFooter className="gap-2 sm:justify-between">
            {adaTitik && !editing ? (
              <Button type="button" variant="outline" asChild>
                <a href={mapsUrl} target="_blank" rel="noopener noreferrer">
                  <ExternalLink className="mr-2 h-4 w-4" />
                  Buka di Google Maps
                </a>
              </Button>
            ) : (
              <span />
            )}

            {editing ? (
              <Button type="button" onClick={simpan} disabled={saving || draftMaps === null}>
                {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                Simpan titik
              </Button>
            ) : (
              <Button type="button" variant="secondary" onClick={() => setEditing(true)}>
                Ubah titik
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
```

- [ ] **Step 2: Gerbang — lint dan build**

```bash
npx eslint src/components/customer-point.tsx
npm run build
```

Diharapkan: eslint tanpa keluaran, build sukses. Kalau eslint mengeluh soal tipe `React.MouseEvent` tanpa import, tambahkan `import type { MouseEvent } from "react"` dan pakai `MouseEvent<HTMLButtonElement>`.

- [ ] **Step 3: Commit**

```bash
git add src/components/customer-point.tsx
git commit -m "feat: tombol dan dialog titik pelanggan"
```

---

### Task 8: Pasang tanda titik di tiga halaman

**Files:**
- Modify: `src/app/(admin)/pengiriman/create/send-form-client.tsx` (type `PickupOrder` ≈baris 40-45, type `DeliveryItem` ≈baris 51-56, baris jemput ≈392, baris antar ≈478)
- Modify: `src/app/(admin)/pengiriman/pickup-waiting/pickup-waiting-client.tsx` (type ≈baris 46-50, sel alamat ≈baris 391-399)
- Modify: `src/app/(admin)/pengiriman/delivery-waiting/delivery-waiting-client.tsx` (type ≈baris 50-54, sel alamat ≈baris 418-423)

**Interfaces:**
- Consumes: `<CustomerPointButton>` dari Task 7; field `customer_id`/`customer_latitude`/`customer_longitude` dari Task 4.
- Produces: tidak ada.

- [ ] **Step 1: Lebarkan tipe baris di ketiga berkas**

Di setiap type yang sudah punya `customer_maps`, tambahkan tiga field. Contoh untuk `PickupOrder` di `send-form-client.tsx`:

```tsx
  customer_id: number
  customer_address: string
  customer_maps: string | null
  customer_latitude: number | null
  customer_longitude: number | null
```

Lakukan hal yang sama untuk `DeliveryItem` di berkas itu, dan untuk type baris di `pickup-waiting-client.tsx` dan `delivery-waiting-client.tsx` (di dua berkas terakhir `customer_address` sudah bertipe `string | null`; jangan diubah).

- [ ] **Step 2: Pasang tombolnya di baris jemput `send-form-client.tsx`**

Tambahkan `import { CustomerPointButton } from "@/components/customer-point"` di blok import. Lalu ganti blok alamat di baris ≈390-393:

```tsx
                      <div className="text-xs text-muted-foreground truncate mt-0.5">
                        📍 {order.customer_address}
                      </div>
                      <div className="mt-0.5 -ml-2">
                        <CustomerPointButton
                          customerId={order.customer_id}
                          customerName={order.customer_name}
                          address={order.customer_address}
                          latitude={order.customer_latitude}
                          longitude={order.customer_longitude}
                          onSaved={(titik) =>
                            setPickupOrders((sebelumnya) =>
                              sebelumnya.map((baris) =>
                                baris.customer_id === order.customer_id
                                  ? {
                                      ...baris,
                                      customer_maps: titik.maps,
                                      customer_latitude: titik.latitude,
                                      customer_longitude: titik.longitude,
                                    }
                                  : baris
                              )
                            )
                          }
                        />
                      </div>
```

Pembaruan dicocokkan berdasarkan `customer_id`, bukan `order.id`: satu pelanggan bisa punya beberapa pesanan di daftar yang sama, dan titik yang baru disimpan berlaku untuk semuanya.

Perhatikan bahwa yang ditulis balik adalah `customer_maps`/`customer_latitude`/`customer_longitude` — nama field di baris daftar — bukan `maps`/`latitude`/`longitude` yang dipakai balasan endpoint. Menyebar `{ ...titik }` apa adanya akan menambah tiga kunci baru dan membiarkan yang lama basi, jadi tandanya tidak berubah sampai halaman dimuat ulang.

Nama state di berkas ini: `const [pickupOrders, setPickupOrders] = useState<PickupOrder[]>([])` (baris 65) dan `const [deliveryItems, setDeliveryItems] = useState<DeliveryItem[]>([])` (baris 66).

- [ ] **Step 3: Pasang tombolnya di baris antar `send-form-client.tsx`**

Ganti blok alamat di baris ≈476-479 dengan pola yang sama, memakai `item` sebagai sumber dan setter state daftar antar:

```tsx
                      <div className="text-xs text-muted-foreground truncate mt-0.5">
                        📍 {item.customer_address}
                      </div>
                      <div className="mt-0.5 -ml-2">
                        <CustomerPointButton
                          customerId={item.customer_id}
                          customerName={item.customer_name}
                          address={item.customer_address}
                          latitude={item.customer_latitude}
                          longitude={item.customer_longitude}
                          onSaved={(titik) =>
                            setDeliveryItems((sebelumnya) =>
                              sebelumnya.map((baris) =>
                                baris.customer_id === item.customer_id
                                  ? {
                                      ...baris,
                                      customer_maps: titik.maps,
                                      customer_latitude: titik.latitude,
                                      customer_longitude: titik.longitude,
                                    }
                                  : baris
                              )
                            )
                          }
                        />
                      </div>
```

- [ ] **Step 4: Pasang tombolnya di `pickup-waiting-client.tsx`**

Tambahkan importnya, lalu di sel alamat (baris ≈391-399) tambahkan sesudah `</div>` penutup blok alamat, di dalam `<TableCell>` yang sama:

```tsx
                      <div className="mt-1 -ml-2">
                        <CustomerPointButton
                          customerId={order.customer_id}
                          customerName={order.customer_name}
                          address={order.customer_address}
                          latitude={order.customer_latitude}
                          longitude={order.customer_longitude}
                          onSaved={(titik) =>
                            setOrders((sebelumnya) =>
                              sebelumnya.map((baris) =>
                                baris.customer_id === order.customer_id
                                  ? {
                                      ...baris,
                                      customer_maps: titik.maps,
                                      customer_latitude: titik.latitude,
                                      customer_longitude: titik.longitude,
                                    }
                                  : baris
                              )
                            )
                          }
                        />
                      </div>
```

State di berkas ini `const [orders, setOrders] = useState<Order[]>([])` (baris 79), dan typenya bernama `Order`.

Sel itu `hidden lg:table-cell`, jadi di layar kecil tombolnya ikut tersembunyi — sama dengan alamatnya hari ini, dan halaman ini dipakai di meja, bukan di jalan.

- [ ] **Step 5: Pasang tombolnya di `delivery-waiting-client.tsx`**

Tambahkan `import { CustomerPointButton } from "@/components/customer-point"`. State di berkas ini `const [items, setItems] = useState<OrderItem[]>([])` (baris 83). Ganti sel alamat (baris ≈417-424) jadi:

```tsx
                    <TableCell className="hidden lg:table-cell">
                      <div className="flex items-start gap-1.5 max-w-[300px]">
                        <MapPin className="h-3.5 w-3.5 text-muted-foreground mt-0.5 flex-shrink-0" />
                        <span className="text-sm text-muted-foreground line-clamp-2">
                          {item.customer_address || "-"}
                        </span>
                      </div>
                      <div className="mt-1 -ml-2">
                        <CustomerPointButton
                          customerId={item.customer_id}
                          customerName={item.customer_name}
                          address={item.customer_address}
                          latitude={item.customer_latitude}
                          longitude={item.customer_longitude}
                          onSaved={(titik) =>
                            setItems((sebelumnya) =>
                              sebelumnya.map((baris) =>
                                baris.customer_id === item.customer_id
                                  ? {
                                      ...baris,
                                      customer_maps: titik.maps,
                                      customer_latitude: titik.latitude,
                                      customer_longitude: titik.longitude,
                                    }
                                  : baris
                              )
                            )
                          }
                        />
                      </div>
                    </TableCell>
```

- [ ] **Step 6: Gerbang — lint dan build**

```bash
npx eslint "src/app/(admin)/pengiriman/create/send-form-client.tsx" "src/app/(admin)/pengiriman/pickup-waiting/pickup-waiting-client.tsx" "src/app/(admin)/pengiriman/delivery-waiting/delivery-waiting-client.tsx"
npm run build
```

Diharapkan: eslint tanpa keluaran, build sukses. Build inilah yang memeriksa tipe — kalau nama field dari Task 4 tidak cocok, di sinilah ketahuan.

- [ ] **Step 7: Periksa dengan mata di aplikasi nyata**

```bash
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
# terminal 1, di shoesfast-app-be
php artisan serve
# terminal 2, di shoesfast-app-fe
npm run dev
```

Buka `http://localhost:3000/pengiriman/create`, masuk, lalu periksa empat hal:

1. Baris pelanggan yang punya titik menampilkan tombol "📍 Titik"; diklik, peta terbuka dengan pin di tempat yang benar dan tidak bisa digeser.
2. "Ubah titik" membuat peta bisa diklik, dan "Simpan titik" menutup dialog dengan toast berhasil. Muat ulang halaman — titiknya tetap.
3. Baris pelanggan tanpa titik menampilkan "Titik belum ada" dan langsung membuka mode taruh pin.
4. Mengklik tombol titik **tidak** ikut mencentang baris tugasnya.

- [ ] **Step 8: Commit**

```bash
git add "src/app/(admin)/pengiriman/create/send-form-client.tsx" "src/app/(admin)/pengiriman/pickup-waiting/pickup-waiting-client.tsx" "src/app/(admin)/pengiriman/delivery-waiting/delivery-waiting-client.tsx"
git commit -m "feat: titik pelanggan terlihat saat tugas kurir dibuat"
```

---

## Sesudah semua tugas selesai

- [ ] **Jalankan kedua gerbang sekali lagi, dari nol**

```bash
# shoesfast-app-be
export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"
composer test && ./vendor/bin/pint --test
# shoesfast-app-fe
npm run lint && npm run build
```

`npm run lint` atas seluruh repo memang sudah merah sejak sebelum pekerjaan ini (364 masalah, semuanya lama). Yang harus bersih adalah `npx eslint` atas berkas yang disentuh.

- [ ] **Catat utangnya di kode**

Tambahkan satu komentar di `src/components/map-picker.tsx`, di atas `parseGoogleMapsUrl`:

```tsx
  // ponytail: parser yang sama hidup juga di shoesfast-customer/components/map-picker.tsx.
  // Lintas repo tidak disatukan. Sejak koordinat diturunkan di server, parser ini hanya
  // dipakai untuk URL→pin di dialog ini — bukan lagi satu-satunya jalan tahu titik
  // pelanggan, jadi menyimpangnya tidak lagi membuat data salah.
```

- [ ] **Langkah manual rilis**

1. Push `shoesfast-app-be` ke `master`. Deploy FTP berjalan sendiri.
2. **Jalankan migrasinya di server** — FTP tidak menjalankan migrasi. SQL padanannya ada di `docs/sql-perubahan-db.md`.
3. Tidak ada berkas `config/` yang berubah di rilis ini, jadi tidak ada config cache yang perlu dibersihkan.
4. Push `shoesfast-app-fe` ke `master` untuk deploy Vercel.
5. Periksa di produksi: buka `pengiriman/create`, pastikan baris pelanggan lama sudah menampilkan "📍 Titik" — itu bukti backfill-nya jalan.
