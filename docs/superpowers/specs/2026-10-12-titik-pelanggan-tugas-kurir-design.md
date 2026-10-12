# Titik jemput/antar pelanggan terlihat saat tugas kurir dibuat

Tanggal: 2026-10-12 · Menyentuh: `shoesfast-app-be`, `shoesfast-app-fe`

## Masalah

Admin membuat tugas kurir dari `pengiriman/create` dan dua halaman daftar
tunggu, tapi tidak bisa melihat di mana pelanggannya berada. Yang tampil hanya
alamat tulisan, satu baris, terpotong. Akibatnya tugas dikirim ke kurir tanpa
ada yang pernah memastikan titiknya masuk akal.

Datanya sebenarnya sudah ada, tapi dalam bentuk yang tidak bisa dipakai:

| Fakta di produksi | Angka |
|---|---|
| Pelanggan aktif | 4.664 |
| Punya `customers.maps` (URL Google Maps) | 3.916 |
| URL itu memuat koordinat `@lat,lng` | 3.913 |
| Kolom `customers.latitude`/`longitude` terisi | 1 |
| `orders.pickup_maps` terisi | 0 dari 5.990 |

Kolom koordinat kosong karena tidak ada satu pun jalur tulis yang mengisinya:
`CustomerController` hanya menerima `maps`, dan form admin
(`customer-client.tsx:997`) menerima `lat`/`lng` dari `MapPicker` lalu
membuangnya. Di sisi lain `SendController` **sudah** mengirim
`customer_latitude`/`customer_longitude` ke aplikasi kurir — isinya selalu
`null`. API menjanjikan angka yang tidak pernah ada.

Jalur portal pelanggan justru sudah benar: `Customer/ProfileController`
menerima `latitude`/`longitude`, dan portalnya punya map picker sendiri.

## Keputusan yang sudah diambil

1. **Kegunaannya hanya melihat dan memastikan sebelum kirim kurir.** Tidak ada
   hitungan jarak, tidak ada ongkos, tidak ada pembagian wilayah, tidak ada
   peta sebaran. Karena itu tidak ada query geo sama sekali di desain ini.
2. **Satu titik per pelanggan.** Pesanan tidak menyimpan titiknya sendiri.
   `orders.pickup_address` dan `orders.pickup_maps` dibiarkan apa adanya —
   mencabut kolom dari skema produksi tidak sepadan dengan untungnya.
3. **Titik kosong diisi admin saat itu**, dari peta di halaman tugas, tersimpan
   ke pelanggannya. Bukan menunggu pelanggan mengisi sendiri.

## Aturan data: `maps` sumbernya, koordinat turunannya

Satu arah, supaya tidak pernah ada dua sumber kebenaran yang berselisih:

- `maps` berubah → `latitude`/`longitude` diturunkan ulang dari URL-nya.
- `maps` dikosongkan → koordinat dikosongkan. Titik basi yang menunjuk rumah
  lama lebih berbahaya daripada titik yang tidak ada, karena ia terlihat benar.
- `maps` kosong tapi klien mengirim `latitude`/`longitude` langsung → angka itu
  dipakai apa adanya. Ini menjaga jalur portal pelanggan.

Ketiga aturan di atas bertabrakan kalau `maps` dikosongkan **sementara**
koordinat dikirim eksplisit dalam satu simpanan yang sama. Urutan menangnya,
ditetapkan di sini supaya tidak ditebak di kode:

1. URL yang bisa diparse selalu menang.
2. Kalau URL kosong atau tidak memuat titik, angka yang dikirim eksplisit pada
   simpanan itu dihormati.
3. Kalau tidak ada keduanya, koordinat dikosongkan.

Aturannya tinggal di **model `Customer`**, lewat hook `saving`, bukan di tiap
controller. Ada lima jalur tulis ke kolom itu hari ini — admin store, admin
update, endpoint titik yang baru, portal profil, portal register — dan jalur
keenam nanti ikut benar tanpa harus diingat siapa pun.

Parser: `App\Support\MapsPoint::fromUrl(?string $url): ?array`, mengembalikan
`['latitude' => float, 'longitude' => float]` atau `null`. Regex
`@(-?\d+\.?\d*),(-?\d+\.?\d*)` — pola yang sudah terbukti di `map-picker.tsx` —
lalu menolak nilai di luar ±90 / ±180. Satu berkas, satu urusan, bisa diuji
tanpa database.

### Backfill

Satu migrasi mengisi koordinat untuk baris yang `latitude`/`longitude`-nya
`NULL` **dan** `maps`-nya memuat `@lat,lng`. Target 3.913 baris.

- Hanya menyentuh baris kosong, jadi aman dijalankan berulang.
- Tidak pernah menimpa titik yang sudah ada.
- Dijaga `Schema::hasColumn` seperti migrasi lain di repo ini, karena produksi
  adalah sumber kebenaran skema.
- Dikerjakan per potongan (chunk) lewat PHP memakai `MapsPoint`, bukan regex
  SQL, supaya aturan penerimaan nilainya sama persis dengan jalur tulis biasa.

SQL padanannya ditulis ke `docs/sql-perubahan-db.md`: deploy FTP tidak
menjalankan migrasi, jadi perintahnya harus ada di tangan manusia.

## Perubahan server

| Berkas | Perubahan |
|---|---|
| `app/Support/MapsPoint.php` | **baru** — parser URL → koordinat |
| `app/Models/Customer.php` | hook `saving` menurunkan koordinat dari `maps` |
| `database/migrations/*_backfill_customer_point.php` | **baru** — backfill 3.913 baris |
| `app/Http/Controllers/Api/CustomerController.php` | **baru** `updatePoint()` |
| `routes/api.php` | `PUT customers/{id}/point` |
| `app/Http/Controllers/Api/SendController.php` | `customer_id` + koordinat di 4 payload |
| `docs/sql-perubahan-db.md` | SQL backfill |

### `PUT /api/customers/{id}/point`

Badan: `{ "maps": "https://..." }` — `nullable|string`. Hanya menulis kolom
`maps`; koordinatnya terisi sendiri oleh hook model.

Endpoint terpisah, bukan melonggarkan `PUT /api/customers/{id}` yang sudah ada.
Endpoint itu menuntut `name` dan `phone`, dan mengubah keduanya jadi `sometimes`
berarti melonggarkan validasi untuk **semua** pemanggil demi satu layar: PUT
yang kelupaan mengirim nama akan diam-diam mempertahankan nama lama. Mengelabui
dengan GET-dulu-lalu-PUT-semuanya juga ditolak — itu baca-ubah-tulis di atas 19
kolom, dan sekali bentuk GET berbeda dari bentuk PUT, `project_ids` atau
`member_code` ikut tertimpa tanpa galat.

Cakupan data mengikuti pola saudaranya di controller yang sama
(`Customer::where('projects_id', 1)->findOrFail($id)`, lihat `update()` dan
`destroy()`). Pelanggan di Shoesfast tidak memakai trait `BranchScoped` —
keanggotaan cabang ada di pivot `customer_project` — jadi menciptakan aturan
cakupan yang berbeda khusus di sini justru yang akan jadi lubang.

Balasan memuat `maps` beserta koordinat hasil turunannya, supaya klien tidak
perlu mem-parse ulang atau menebak apa yang tersimpan.

Rutenya didaftarkan **sebelum** `Route::apiResource('customers', ...)`,
mengikuti aturan repo ini. Kedalaman jalurnya berbeda sehingga secara teknis
tidak akan tertutup `{id}`, tapi menaruhnya di tempat yang sama dengan rute aksi
lain membuat urutan itu tetap satu pola, bukan pengecualian yang harus diingat.

### Payload pembuat tugas

`getAvailablePickupOrders`, `getAvailableDeliveryItems`, `pickupWaitingList`,
dan `deliveryWaitingList` menambahkan:

```php
'customer_id' => $order->customer->id,
'customer_latitude' => $order->customer->latitude === null ? null : (float) $order->customer->latitude,
'customer_longitude' => $order->customer->longitude === null ? null : (float) $order->customer->longitude,
```

Tanpa `customer_id`, UI tidak punya alamat untuk menyimpan titik. Koordinat
dikirim sebagai **angka**, bukan hanya URL, mengikuti dua payload di
`SendController` yang sudah melakukannya (baris ≈803 dan ≈916).

## Perubahan admin

**Tidak ada peta di dalam baris daftar.** Ketiga halaman itu daftar padat
sampai 50 baris; 50 instance Leaflet memberatkan halaman tanpa guna, karena
admin hanya melihat satu baris pada satu waktu.

Tiap baris dapat satu tanda titik di samping alamatnya, yang menjawab "yakin
atau tidak" tanpa perlu diklik:

- Titik ada → tanda netral "📍 Titik". Klik membuka dialog peta read-only,
  dengan tautan buka di Google Maps dan tombol "Ubah titik".
- Titik tidak ada → tanda abu "Titik belum ada". Klik langsung membuka peta
  mode taruh pin.

**Tidak ada komponen peta baru.** `MapPicker` yang sudah ada ditambahi satu
prop `readOnly`: saat `true`, klik peta tidak mengikat, dan kolom URL manual
serta tombol "pakai lokasi saya" disembunyikan. Itu lebih kecil daripada
menulis peta read-only kedua yang perilakunya akan menyimpang pelan-pelan.

| Berkas | Perubahan |
|---|---|
| `src/components/customer-point.tsx` | **baru** — `<CustomerPointButton>`: tanda, dialog, simpan. Satu komponen, tiga halaman |
| `src/components/map-picker.tsx` | tambah prop `readOnly` |
| `pengiriman/create/send-form-client.tsx` | tanda titik di baris jemput (≈392) dan baris antar (≈478) |
| `pengiriman/pickup-waiting/pickup-waiting-client.tsx` | tanda titik di baris (≈395) |
| `pengiriman/delivery-waiting/delivery-waiting-client.tsx` | tanda titik di baris |

`<CustomerPointButton>` menerima angka dan tidak mem-parse apa pun. Leaflet
dimuat hanya saat dialognya terbuka, supaya tiga halaman daftar itu tidak
kebagian berat petanya saat halaman dibuka.

`customer-client.tsx:997` yang membuang `lat`/`lng` dibiarkan. Koordinat
sekarang diturunkan di server dari `maps` yang form itu memang sudah mengirim,
jadi menambal baris itu berarti mengirim angka yang tidak dibutuhkan.

## Galat

| Keadaan | Perilaku |
|---|---|
| URL tanpa `@lat,lng` (3 baris di produksi) | Koordinat tetap `null`. UI menampilkan "Titik belum ada". Bukan galat |
| Angka di luar ±90 / ±180 | Parser mengembalikan `null` — dianggap tidak ada titik, bukan menyimpan titik ngawur |
| `maps` bukan URL (3 baris berisi teks lain) | Sama: `null`, "Titik belum ada" |
| Pelanggan tidak ditemukan di `PUT /point` | 404 dari `findOrFail`, sama dengan saudaranya |
| Simpan titik gagal (jaringan / 500) | Dialog tetap terbuka, toast galat, titik lama tidak hilang. Admin bisa coba lagi tanpa kehilangan pin yang baru ditaruh |
| Dialog dibuka saat pelanggan belum punya alamat maupun titik | Peta terbuka di titik bawaan `MapPicker` yang sudah ada (`map-picker.tsx:127`), bukan di tengah laut. Titik cabang TIDAK dijadikan pusat peta — itu menambah ketergantungan pada data cabang demi keadaan yang jarang |

## Pengujian

**Unit, tanpa database** — `tests/Unit/MapsPointTest.php`:
`google.com/maps/place/...@lat,lng`, `google.co.id` (173 baris produksi memakai
domain ini), URL tanpa koordinat, lintang di luar rentang, bujur di luar
rentang, string kosong, `null`, dan teks yang bukan URL.

**Feature** — `tests/Feature/TitikPelangganTest.php`:

1. Menyimpan pelanggan dengan `maps` berisi `@lat,lng` → kolom koordinat terisi.
2. `maps` dikosongkan → koordinat jadi `null`.
3. Klien mengirim `latitude`/`longitude` tanpa `maps` → angka itu dipakai
   (jalur portal pelanggan tidak rusak).
4. `maps` dan koordinat dikirim bersama tapi berbeda → yang dari `maps` menang.
5. `PUT /api/customers/{id}/point` menulis `maps`, membalas koordinat
   turunannya, dan **tidak** mengubah `name`/`phone`/`project_ids`.
6. Payload `available-pickup-orders` memuat `customer_id` dan koordinat.
7. Migrasi backfill mengisi baris yang koordinatnya `NULL`, dan tidak menyentuh
   baris yang sudah terisi walau dijalankan dua kali.

**Admin** tidak punya framework test (lihat `shoesfast-app-fe/CLAUDE.md`);
gerbangnya `npm run build` dan `npx eslint` atas berkas yang disentuh.

## Yang sengaja TIDAK dikerjakan

- Hitungan jarak, ongkos antar, radius layanan, pembagian wilayah, pengurutan
  rute. Tidak diminta, dan masing-masing menuntut keputusan pemilik sendiri.
- Peta sebaran pelanggan.
- Titik per pesanan. `orders.pickup_*` tetap kosong dan tidak dipakai.
- Tautan WhatsApp supaya pelanggan menaruh pin sendiri.
- Navigasi peta di dalam aplikasi kurir. Kurir tetap memakai tombol buka-Maps
  eksternal yang sudah ada — tapi mulai sekarang ia juga menerima koordinat
  angka yang sebelumnya selalu `null`, jadi pekerjaan itu bisa menyusul tanpa
  perubahan server lagi.

## Utang yang dicatat

Parser `@lat,lng` tetap hidup di dua repo: `shoesfast-app-fe` (di dalam
`map-picker.tsx`, dipakai untuk URL→pin di dialognya) dan `shoesfast-customer`.
Lintas repo tidak disatukan. Ditandai `ponytail:` di kedua tempat.

## Langkah manual saat rilis

1. Push `shoesfast-app-be` ke `master` — deploy FTP berjalan sendiri.
2. **Jalankan migrasinya di server.** FTP tidak menjalankan migrasi. SQL
   padanannya ada di `docs/sql-perubahan-db.md`.
3. Kalau server pernah di-`config:cache`, tidak ada yang perlu dibersihkan di
   rilis ini — tidak ada berkas `config/` yang berubah.
4. Push `shoesfast-app-fe` ke `master` untuk deploy Vercel.
