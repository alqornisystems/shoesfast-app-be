# Invoice Share Link — Desain (bagian backend)

Tanggal: 2026-07-27
Status: disetujui, siap dibuatkan rencana implementasi
Pasangan: `shoesfast-app-fe/docs/superpowers/specs/2026-07-27-invoice-share-link-design.md`
(bagian frontend: halaman publik, lightbox foto, tombol admin)

Dokumen ini berdiri sendiri — pengerjaan backend tidak perlu membuka repo frontend.

## Ringkasan

Invoice berhenti berupa PDF yang diunduh admin. Tiap order mendapat satu tautan publik yang
disalin admin lalu dikirim ke customer lewat WhatsApp. Backend menyediakan dua hal: endpoint
bagi admin untuk membuat/menyegarkan tautan, dan endpoint publik tanpa login yang
mengembalikan seluruh isi invoice — termasuk foto tiap item dan harga per treatment.

## Keadaan sekarang

- Invoice dibuat sepenuhnya di sisi klien dengan jsPDF. Backend tidak punya kode invoice sama
  sekali; frontend merakitnya dari dua panggilan yang sudah ada,
  `GET /api/orders/{id}/items` dan `GET /api/payments/order/{id}`.
- `orders_items.photo` **sudah ada**: disimpan sebagai path storage, dikembalikan sebagai URL
  penuh lewat `asset('storage/…')` di `OrderController.php:123-137`. URL ini publik, bisa
  dibuka tanpa login — tidak ada yang perlu diubah di sisi penyimpanan foto.
- Satu `orders_items` punya banyak `treatments`, dan **tiap treatment punya harganya sendiri**
  (`OrderController.php:104-118`). PDF lama membuang harga itu dan hanya menggabung nama
  layanan jadi satu string.
- Route publik di luar `auth:sanctum` sudah ada presedennya: `POST /api/webhook`.

## Keputusan yang mengikat backend

1. **Token acak, bukan ID order.** `/invoice/<token>` dengan token 40 karakter. Memakai
   `/invoice/123` membuat siapa pun bisa menaikkan angkanya dan membaca invoice customer lain
   — nama, telepon, alamat, harga, riwayat bayar — karena halaman ini tanpa login.

2. **Masa berlaku 30 hari, token tidak berubah saat disegarkan.** Tiap admin menekan "Salin
   Link", `invoice_expires_at` di-reset 30 hari ke depan sementara `invoice_token` tetap sama.
   Customer yang menyimpan tautan lama di chat tinggal membukanya lagi. Tanpa admin
   menyegarkan, tautan mati setelah 30 hari.

3. **Angka uang tidak dihitung ulang dengan rumus baru.** `due_date`, `total_paid`, `credit`,
   dan `payment_status` disalin persis dari `PaymentController.php:77-105` supaya invoice
   publik dan halaman pembayaran tidak pernah berselisih.

## Lingkup

Masuk: dua kolom baru di `orders`, satu key config, dua endpoint, satu controller baru, satu
feature test.

Keluar: pengiriman otomatis lewat WhatsApp/WAHA (admin menyalin dan mengirim sendiri),
pencabutan tautan sebelum masa berlaku habis, rendering PDF di server, dan segala pipeline
foto ganda / putaran 360 derajat.

## Migration

Satu migration menambah dua kolom di `orders`. Dijaga `Schema::hasColumn` agar idempoten
terhadap DB produksi yang sudah termigrasi, sesuai aturan di CLAUDE.md.

```php
$table->string('invoice_token', 40)->nullable()->unique()->after('note');
$table->integer('invoice_expires_at')->nullable()->after('invoice_token');
```

`invoice_expires_at` bertipe integer unix detik, mengikuti konvensi tabel ini
(`$dateFormat = 'U'`, kolom tanggal di-cast `integer`). Method `down()` melepas index unique
sebelum menghapus kolom.

## Model

`App\Models\Order`: tambahkan `invoice_token` dan `invoice_expires_at` ke `$fillable`, dan
`invoice_expires_at` ke `$casts` sebagai `integer`.

## Config

`FRONTEND_URL` saat ini hanya dibaca lewat `env()` langsung di `config/cors.php:17`, dan
isinya **boleh berupa beberapa origin yang dipisah koma**. Tidak ada key config yang bisa
dipanggil dari controller. Tambahkan di `config/app.php`:

```php
'frontend_url' => trim(explode(',', (string) env('FRONTEND_URL', ''))[0]),
```

Diambil entri pertama karena isinya bisa jamak, dan ditaruh di config (bukan `env()` di
controller) supaya tetap benar saat `config:cache` aktif di produksi. Kalau nilainya kosong,
endpoint pembuat tautan menjawab 500 dengan pesan jelas — lebih baik gagal keras daripada
menyalin tautan `/invoice/<token>` tanpa domain.

## Endpoint 1 — buat / segarkan tautan (butuh auth)

```
POST /api/orders/{id}/invoice-link
→ 200 { "url": "https://app.example.com/invoice/<token>", "expires_at": 1790000000 }
```

Method `invoiceLink` di `OrderController`. Route **dideklarasikan sebelum**
`Route::apiResource('orders', …)` mengikuti aturan urutan route yang sudah dipegang proyek
ini.

Logika:

1. Ambil order lewat scope biasa — admin sudah login, branch scope berlaku normal sehingga
   admin cabang tidak bisa membuat tautan untuk order cabang lain.
2. Kalau `invoice_token` kosong, isi `Str::random(40)`. Kalau sudah ada, **biarkan apa
   adanya**.
3. Set `invoice_expires_at = time() + 30 * 86400`, selalu, baik token baru maupun lama.
4. Simpan, lalu rakit URL: `config('app.frontend_url') . '/invoice/' . $token`.

## Endpoint 2 — baca invoice (publik)

```
GET /api/public/invoice/{token}
→ 200 payload | 404 token tidak dikenal | 410 tautan kedaluwarsa
```

Controller baru `App\Http\Controllers\Api\PublicInvoiceController`. Route ditaruh **di luar**
grup `auth:sanctum`, bersebelahan dengan `webhook`, dan diberi `throttle:60,1` supaya token
40 karakter tidak bisa digempur brute force.

Pencarian order ditulis eksplisit tanpa branch scope:

```php
$order = Order::withoutBranchScope()->where('invoice_token', $token)->first();
```

`BranchContext::getActiveBranch()` memang sudah mengembalikan `null` saat tidak ada user login
(`BranchContext.php:31-35`) sehingga global scope-nya diam dengan sendirinya — tapi
mengandalkan itu berarti fitur ini diam-diam bergantung pada perilaku yang tidak pernah
dijanjikan. `withoutBranchScope()` membuat niatnya terbaca dan tahan kalau `BranchContext`
berubah.

Alur:

- Order tidak ketemu → 404 `{ "message": "Invoice tidak ditemukan" }`
- `invoice_expires_at` kosong atau `< time()` → 410, **beserta data cabang**:

  ```json
  {
    "message": "Link invoice sudah kedaluwarsa",
    "branch": { "name": "Cabang Kemang", "phone": "0812xxxxxxx" }
  }
  ```

  Cabang ikut dikirim karena halaman kedaluwarsa harus memberi tahu customer harus
  menghubungi siapa. Tanpa ini, satu-satunya jalan keluar bagi customer adalah menebak.
  Tidak ada data pribadi yang bocor — hanya nama dan nomor toko.
- Selain itu → 200 dengan payload di bawah

Eager load `customer`, `project`, `items.treatments.service`, dan `payments` supaya tidak
kena N+1.

## Bentuk payload

Menyatukan yang sekarang butuh dua panggilan menjadi satu:

```json
{
  "code": "INV-2026-001",
  "date": 1785000000,
  "due_date": 1785259200,
  "payment_status": "paid",
  "branch": { "name": "Cabang Kemang", "phone": "0812xxxxxxx" },
  "customer": { "name": "Budi Santoso", "phone": "0812…", "email": null, "address": "Jl. …" },
  "items": [
    {
      "name": "Nike Air Force 1",
      "photo": "https://api.example.com/storage/items/item-12-1.jpg",
      "price": 195000,
      "discount": 0,
      "treatments": [
        { "name": "Deep Clean", "price": 75000 },
        { "name": "Unyellowing", "price": 120000 }
      ]
    }
  ],
  "total_price": 195000,
  "total_paid": 195000,
  "credit": 0,
  "payments": [
    { "date": 1785300000, "nominal": 195000, "note": "Transfer BCA" }
  ]
}
```

Catatan isi:

- `photo` memakai konversi path→URL yang sama dengan `OrderController.php:123-137` (kalau
  nilainya sudah URL, dipakai apa adanya; kalau path, dibungkus `asset('storage/…')`).
- `treatments[].name` diambil dari `treatment->service->name`, `treatments[].price` dari
  `treatment->price`.
- `item.price` tetap dikirim dan dipakai frontend sebagai subtotal yang mengikat. Jumlah harga
  treatment bisa berbeda dari `item.price` karena kolom itu bisa diisi manual — yang ditagih
  tetap `item.price`.
- `branch.phone` dipakai halaman "tautan kedaluwarsa" agar customer tahu harus menghubungi
  siapa.
- `customer` bisa null untuk order walk-in; kirim objek dengan field bernilai null, jangan
  hilangkan kuncinya.

## Kasus pinggir

- Order tanpa item → `items: []`.
- Relasi `customer` atau `project` terhapus → field-nya null, bukan error.
- Item tanpa treatment → `treatments: []`.
- `photo` null → `photo: null`.
- Order yang belum pernah disalin tautannya → `invoice_token` null, tidak ada tautan yang bisa
  dibuka. Wajar.

## Pengujian

Satu feature test baru (proyek memakai `composer test`, sudah ada dua test):

- token dibuat saat pertama diminta, dan **tetap sama** saat diminta ulang
- `invoice_expires_at` ter-reset tiap permintaan
- `GET /api/public/invoice/{token}` menjawab 200 untuk token hidup, 404 untuk token asing,
  410 untuk token yang sudah lewat masa berlaku
- endpoint publik bisa diakses tanpa header `Authorization`
- angka `total_paid` / `credit` / `payment_status` cocok dengan yang dikeluarkan
  `PaymentController`

Ingat: `php` dan `composer` di mesin ini butuh XAMPP di depan `PATH` —
`export PATH="/Applications/XAMPP/xamppfiles/bin:$PATH"`.

## Batasan yang diambil sadar

- Tidak ada cara mencabut tautan sebelum masa berlakunya habis. Kalau nanti dibutuhkan,
  tambahkan kolom `invoice_revoked_at` dan satu pemeriksaan di endpoint publik.
- Payload tidak di-cache. Beban kecil karena tautan hanya dibuka customer bersangkutan, dan
  status pembayaran harus selalu mutakhir.

## Spec lanjutan — putaran 360 derajat

Dikerjakan terpisah setelah fitur ini jalan. 360 sungguhan butuh 24-36 frame per item,
sementara jalur upload sekarang menempelkan base64 di dalam JSON payload — 36 frame lewat
jalur itu berarti satu POST berisi 7-10 MB dan akan gagal. Jadi 360 memerlukan tabel foto
baru, endpoint upload multi-file (bukan base64), UI upload di admin, viewer di halaman publik,
plus alur kerja staff memotret memakai turntable. Tidak ada bagian dari spec ini yang perlu
dibongkar untuk mengerjakannya.
