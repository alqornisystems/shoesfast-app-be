# Migrasi API aplikasi staf: `/mobiles/*` (Slim) → `shoesfast-app-be` (Laravel 12)

Dokumen ini untuk tim aplikasi mobile kurir & teknisi. Isinya: jalur lama dipetakan ke jalur
baru, apa yang berubah di autentikasi, dan bentuk data mana yang akan membuat aplikasi lama
pecah kalau tidak disesuaikan.

Basis URL baru: `https://<host>/api`. Semua contoh di bawah relatif terhadap itu.

> **Catatan tentang kolom "jalur lama".** Kode Slim lama tidak ada di ruang kerja tempat
> dokumen ini disusun. Nama jalur lama yang **dikutip persis** dari brief migrasi ditulis apa
> adanya (`sign-in`, `reorder-send`, `list-items`, `save-item`, `delete-item`, `save-issue`,
> `list-general`). Baris lain ditandai *(cek nama lama)* — kemampuannya sudah pasti ada
> padanannya, tapi ejaan jalur lamanya harap dicocokkan sendiri dengan kode Slim sebelum
> dipakai sebagai daftar centang.

> **Dokumen ini menjelaskan kontrak yang sudah ada.** Untuk hal yang masih terbuka —
> verifikasi PIN absensi, kalender kehadiran, staging — lihat
> [`jawaban-pertanyaan-mobile.md`](./jawaban-pertanyaan-mobile.md). Begitu keputusannya
> turun, hasilnya pindah ke sini dan dokumen itu boleh dibuang.

---

## 1. Tabel pemetaan

### Autentikasi

| `/mobiles/*` lama | Jalur Laravel baru | Catatan |
|---|---|---|
| `POST /mobiles/sign-in` | `POST /auth/login` | Badan: `{phone, password, remember_me?}`. Mengembalikan `token`. Lihat §2. |
| — (tidak ada) | `GET /auth/me` | Baru. Ambil profil + cabang aktif tanpa login ulang. |
| — (tidak ada) | `POST /auth/logout` | Baru. Mencabut token yang sedang dipakai. |
| — (tidak ada) | `POST /auth/refresh` | Baru. Tukar token yang mau kedaluwarsa dengan yang baru. |
| — (tidak ada) | `PUT /auth/profile`, `PUT /auth/change-password` | Baru. |

### Absensi

| `/mobiles/*` lama | Jalur Laravel baru | Catatan |
|---|---|---|
| absen hari ini *(cek nama lama)* | `GET /attendances/today` | `clock_in`/`clock_out` masing-masing objek atau `null`. |
| absen masuk *(cek nama lama)* | `POST /attendances/clock-in` | Wajib `latitude`, `longitude`; `is_wfa` opsional. Radius 1 km. |
| absen pulang *(cek nama lama)* | `POST /attendances/clock-out` | **Aturan baru: ditahan catatan harian.** Lihat §3.7. |
| riwayat absensi *(cek nama lama)* | `GET /attendances` | `start_date`, `end_date`, dan (khusus admin) `user_id`, `report_mode`. |
| — | `GET /absences` · `POST /absences` · `DELETE /absences/{id}` | Pengajuan izin. Non-admin hanya melihat miliknya sendiri. |
| — | `PUT /absences/{id}/approve` · `/reject` | Hanya Admin & Admin Super. Bukan untuk aplikasi lapangan. |

### Catatan harian

| `/mobiles/*` lama | Jalur Laravel baru | Catatan |
|---|---|---|
| `POST /mobiles/save-issue` | `POST /daily-notes` (buat) · `PUT /daily-notes/{id}` (ubah) | Dipecah dua. Tidak ada lagi "lempar seluruh request ke insert": kolom yang ditulis hanya `note`, `activities`, `date`. |
| daftar catatan *(cek nama lama)* | `GET /daily-notes` | Filter `start_date`, `end_date`. Non-admin selalu dipaksa ke catatannya sendiri. |
| catatan hari ini *(cek nama lama)* | `GET /daily-notes/today` | `{"data": null}` kalau belum ada — bukan 404. |
| tandai selesai *(cek nama lama)* | `PUT /daily-notes/{id}/toggle-status` | Badan `{status: 0\|1}`. Hanya pemilik catatan. |
| hapus catatan *(cek nama lama)* | `DELETE /daily-notes/{id}` | Hapus lunak. Hanya pemilik catatan — dulu siapa pun bisa. |
| `GET /mobiles/list-general` | **tidak ada padanan** | Sisa debug (`print_r`). Tidak dipindahkan, tidak akan dibuat. |

### Pengerjaan (teknisi)

| `/mobiles/*` lama | Jalur Laravel baru | Catatan |
|---|---|---|
| daftar pengerjaan *(cek nama lama)* | `GET /treatments` | Mode lewat `page_type`: `waiting_list` (bawaan), `pengerjaan`, `pengerjaan-vendor`, `history`. Balasan berbentuk paginator. |
| ubah status pengerjaan *(cek nama lama)* | `PUT /treatments/{id}/status` | `status`: 0 dikerjakan, 1 siap QC, 2 lolos QC. **Non-admin hanya boleh 0 → 1**; setelah itu 403. |
| ambil pekerjaan *(cek nama lama)* | `POST /treatments/claim` | Badan `{treatment_ids: []}`. Mengembalikan `diambil` dan `ditolak`. |
| daftar layanan *(cek nama lama)* | `GET /services` | **Dibuka untuk Teknisi & Kurir.** Muatan mereka tanpa `price` dan tanpa `hpp`. Lihat §3.6. |

### Pengiriman & jemput (kurir)

| `/mobiles/*` lama | Jalur Laravel baru | Catatan |
|---|---|---|
| tugas berjalan *(cek nama lama)* | `GET /sends/in-progress` | Kurir hanya melihat tugasnya sendiri. Filter `type` (0 jemput, 1 antar). |
| riwayat tugas *(cek nama lama)* | `GET /sends/history` | Idem, plus `start_date`/`end_date`. |
| selesaikan tugas *(cek nama lama)* | `POST /sends/mark-completed` | Badan `{ids: []}`. |
| rincian barang *(cek nama lama)* | `GET /sends/{id}/detail` | Kelengkapan, pengerjaan, status pembayaran. Kurir hanya boleh membuka pengirimannya sendiri (403 kalau bukan). |
| — | `GET /sends/pickup-waiting-list` · `GET /sends/delivery-waiting-list` | Antrean tugas yang belum ada kurirnya. |
| — | `POST /sends` · `PUT /sends/{id}` | Membuat/mengubah tugas. `users_id` dari klien diabaikan untuk non-admin, selalu ditimpa pemegang token. |
| `POST /mobiles/reorder-send` | `POST /sends/{id}/reorder` | **Berubah bentuk**: id pengiriman sekarang di jalur, bukan di badan. Lihat §5.1. |

### Barang pesanan

| `/mobiles/*` lama | Jalur Laravel baru | Catatan |
|---|---|---|
| `GET /mobiles/list-items` | `GET /orders/{orderId}/items` | Dibuka untuk Teknisi & Kurir, **tanpa harga**. Lihat §3.6. Untuk layar rincian satu barang antaran, `GET /sends/{id}/detail` biasanya sudah cukup dan lebih hemat. |
| `POST /mobiles/save-item` | `POST /orders/{orderId}/items` | Simpan/ubah barang. Harga tidak lagi diterima dari perangkat lapangan. Lihat §5.2. |
| `POST /mobiles/delete-item` | `DELETE /orders/{orderId}/items/{itemId}` | Berubah jadi `DELETE` dengan id di jalur. |

### Tidak dipindahkan

| Yang lama | Alasan |
|---|---|
| `users_id` di query/badan tiap endpoint | Identitas sekarang selalu dari token. Kirim `users_id` tidak akan berpengaruh — di jalur mana pun nilainya diabaikan atau ditimpa. |
| Bendera `is_web` untuk melewati aturan catatan harian | Parameter yang bisa dikirim siapa saja bukan pembebasan. Diganti pembebasan berbasis jabatan (§3.6). |
| `GET /mobiles/list-general` | Sisa debug. |
| Perhitungan telat (`jam > 8 ATAU menit >= 40`) | Salah: absen 07:45 terhitung telat. Tidak ditiru. `GET /attendances` tidak mengembalikan penanda telat sama sekali. |
| Perhitungan alpa yang mencampur "Izin" dengan "Alpha" | Salah. Tidak ditiru. Rekapnya ada di `GET /reports/attendance` — tapi jalur itu dikunci `role:Admin Super,Admin,HRD`, jadi **Teknisi & Kurir dapat 403**. Untuk aplikasi lapangan jalur ini buntu; lihat jawaban §2. |
| Password `sha1` | Login masih menerima hash sha1 lama supaya akun lama tidak terkunci, tapi password baru ditulis bcrypt. Tidak ada perubahan di sisi aplikasi. |

---

## 2. Autentikasi — yang paling banyak berubah

### 2.1 Login mengembalikan token

`POST /auth/login`, badan `{"phone": "...", "password": "...", "remember_me": false}`.

```json
{
  "message": "Login berhasil.",
  "token": "12|xxxxxxxxxxxxxxxxxxxxxxxx",
  "expires_at": "2026-08-10T09:00:00.000000Z",
  "user": { "id": 7, "name": "...", "email": "...", "role": "Kurir",
            "projects_id": 1, "project_name": "...", "is_super_admin": false },
  "branch": { "active_id": null, "active_name": "Tidak Ada", "can_switch": false }
}
```

- Nomor telepon boleh dikirim dalam bentuk apa pun (`08xxx`, `62xxx`, `+62xxx`, `8xxx`) —
  server menormalkannya sebelum mencari akun.
- Salah nomor/PIN → **401** `{"message": "Nomor telepon atau PIN salah."}` (pesan sama untuk
  kedua sebab, sengaja).
- Login dibatasi **6 percobaan per menit per IP** → **429**.
- Masa berlaku token: 1 hari; dengan `remember_me: true` jadi 30 hari. Objek `branch` pada
  balasan login selalu berisi nilai kosong seperti contoh di atas — ambil cabang aktif dari
  `GET /auth/me`, bukan dari balasan login.

### 2.2 Token dikirim sebagai header

Setiap permintaan selain login:

```
Authorization: Bearer <token>
Accept: application/json
```

`Accept: application/json` **wajib**. Tanpa itu, permintaan yang gagal autentikasi tidak
dijawab JSON dan aplikasi akan menerima balasan yang tidak bisa diurai.

Token kedaluwarsa atau dicabut → **401**. Perlakukan sebagai "kembali ke layar login".

### 2.3 `users_id` tidak dikirim lagi — di mana pun

Di API lama tiap endpoint menerima `users_id` mentah dari query string, sehingga siapa pun
bisa absen atau menghapus catatan atas nama orang lain. Itu hilang sepenuhnya:

- Hapus `users_id` dari **semua** permintaan: badan, query, header.
- Absen, catatan harian, klaim pekerjaan, dan tugas kirim semuanya memakai pemilik token.
- Di `POST /sends` dan `PUT /sends/{id}` kolom `users_id` masih ada di validasi (dipakai admin
  untuk menugaskan orang lain), tapi untuk Teknisi/Kurir nilainya **selalu ditimpa** dengan
  dirinya sendiri. Jangan andalkan nilai yang dikirim.
- `projects_id` (cabang) tidak pernah diterima dari klien. Ditentukan dari akun.

### 2.4 Satu sesi per akun

Login mencabut semua token akun itu yang masih hidup. Login di perangkat kedua akan
mementalkan perangkat pertama ke 401 pada permintaan berikutnya.

---

## 3. Perubahan bentuk data yang akan memecahkan aplikasi lama

### 3.1 Tanggal: unix detik, dengan satu pengecualian

**Semua** kolom waktu pada balasan adalah **bilangan bulat unix detik**, bukan string:
`date`, `clock`, `time`, `created_at`, `modified_at`, `date_start`, `date_end`, `done_at`,
`due_date`, `expires_at` (kecuali `expires_at` pada balasan login, yang berupa string ISO-8601).

Satu pengecualian yang harus ditangani khusus:

| Endpoint | Kunci | Bentuk |
|---|---|---|
| `GET /attendances` | `data[].date` | **String `"Y-m-d"`**, misalnya `"2026-08-09"` |
| `GET /attendances` | `data[].clock_in.time`, `data[].clock_out.time` | Unix detik (int) |

Jadi satu balasan yang sama memuat dua bentuk sekaligus. `data[].duration` adalah **detik**,
bukan menit atau string jam.

Untuk **masukan**, konvensinya dua-duanya ada — perhatikan per endpoint:

| Endpoint | Kolom | Bentuk yang diterima |
|---|---|---|
| `POST /daily-notes` | `date` | String tanggal (`Y-m-d` atau apa pun yang dipahami `strtotime`) |
| `POST /absences` | `date_start`, `date_end` | String tanggal |
| `POST /sends`, `PUT /sends/{id}` | `date` | String tanggal |
| `GET /attendances`, `GET /daily-notes`, `GET /sends/history` | `start_date`, `end_date` | String tanggal |
| `POST /treatments/assign`, `PUT /treatments/{id}/update` | `date_start`, `date_end` | **Integer unix detik** |

`end_date` selalu diperlakukan inklusif (server menambahkan `23:59:59` sendiri).

### 3.2 Nomor telepon: tidak ada awalan `62` pada balasan

Nomor disimpan dan dikembalikan dalam bentuk **`8xxx`** — tanpa `0`, tanpa `62`, tanpa `+`.
Berlaku untuk semua kunci: `customer_phone`, `courier_phone`, `customers_phone`, `phone`.

- Aplikasi lama yang menampilkan atau membuat tautan `wa.me` dari nilai mentah **harus
  menambahkan `62` sendiri**: `"62" + phone`.
- Untuk dial biasa, `"0" + phone`.
- Sebaliknya, saat **mengirim** nomor (login), bentuk apa pun diterima.
- Hanya `phone` pelanggan dan pengguna yang dinormalkan. `projects.phone`/`whatsapp` dan
  nomor mitra tersimpan apa adanya — jangan diberi awalan otomatis.

### 3.3 URL foto: sebagian penuh, sebagian relatif, tidak ada `no-photo.png`

**Tidak ada fallback di server.** Berkas `no-photo.png` tidak ada di backend baru. Kalau tidak
ada foto, nilainya **`null`**. Placeholder harus disiapkan aplikasi.

Sudah berupa URL absolut (`https://host/storage/...`):

| Endpoint | Kunci |
|---|---|
| `GET /orders/{orderId}/items` | `photo` |
| `GET /orders/{id}` | `items[].photo` |
| `GET /sends/{id}/detail` | `item_photo` |
| `GET /sends/delivery-waiting-list`, `GET /sends/available-delivery-items` | `photo` |

Masih berupa **jalur relatif mentah** — harus digabung sendiri dengan `<APP_URL>/storage/`:

| Endpoint | Kunci | Isi |
|---|---|---|
| `GET /treatments` | `orders_items_photo` | `orders_items/xxx.jpg` |
| `GET /absences` | `photo` | nama berkas saja |
| `GET /services` | `photo` | apa adanya dari kolom |

Dua catatan penting:

1. `GET /absences` → `photo` menyimpan **nama berkas tanpa awalan folder**, padahal berkasnya
   ada di `absences/`. Menggabung `storage/` + nilai itu akan 404. Sampai diperbaiki di
   backend, susun sebagai `storage/absences/<nilai>`.
2. Kolom foto pengguna/pelanggan/hadiah bisa berisi URL absolut **atau** data-URL base64
   (`data:image/...`). Periksa awalannya sebelum memperlakukannya sebagai URL.

**Unggah foto** semuanya lewat JSON, bukan multipart:

| Endpoint | Kolom | Bentuk |
|---|---|---|
| `POST /orders/{orderId}/items` | `photo` | **data-URL wajib**: `data:image/jpeg;base64,...`. Tanpa awalan itu → 500. |
| `POST /absences` | `photo` | base64 **polos**, tanpa awalan `data:`. Selalu disimpan `.jpg`. |

Jangan mengirim balik URL yang baru saja dibaca dari `getItems` ke `saveItem` — akan ditolak
sebagai format gambar tidak sah.

### 3.4 Kode status: 403 lama menjadi 422

API lama memakai **403 untuk validasi gagal**. Di Laravel arti tiap kode dipisah tegas:

| Kode | Arti sekarang | Contoh |
|---|---|---|
| **401** | Belum/tidak lagi terautentikasi | Token habis atau tidak dikirim |
| **403** | Terautentikasi tapi jabatan/kepemilikan tidak mengizinkan | `{"message": "Jabatan Anda tidak memiliki akses ke menu ini."}`, `{"message": "Ini bukan pengiriman Anda."}` |
| **404** | Data tidak ditemukan | `findOrFail` |
| **422** | Validasi gagal **atau** aturan bisnis menolak | Lihat di bawah |
| **429** | Terlalu sering | Login 6×/menit |
| **500** | Kesalahan server | Ada kunci `error` berisi pesan asli |

Yang harus diubah di aplikasi: **cabang penanganan 403 yang dulu berarti "input salah"
sekarang harus menangani 422.** Kalau tidak, pesan kesalahan validasi tidak akan muncul dan
403 yang sekarang berarti "tidak berhak" akan disalahartikan.

Dua bentuk badan 422 yang berbeda:

```jsonc
// Validasi Laravel
{ "message": "The phone field is required.", "errors": { "phone": ["The phone field is required."] } }

// Penolakan aturan bisnis — hanya `message`, tanpa `errors`
{ "message": "Anda sudah absen masuk hari ini" }
```

Pesan validasi Laravel masih **berbahasa Inggris** (belum ada berkas terjemahan); pesan aturan
bisnis berbahasa Indonesia. Tampilkan `errors.<field>[0]` bila ada, kalau tidak `message`.

Pesan 404 dari `findOrFail` juga berbahasa Inggris dan bisa kosong di produksi — jangan
ditampilkan mentah ke pengguna.

### 3.5 Amplop balasan tidak seragam

Tiga bentuk berbeda, jangan disamakan satu parser:

| Bentuk | Endpoint |
|---|---|
| `{"data": [...]}` | `/attendances`, `/absences`, `/daily-notes`, `/daily-notes/today`, `/sends/in-progress`, `/sends/history`, `/sends/pickup-waiting-list`, `/sends/delivery-waiting-list` |
| Larik telanjang `[...]` | `/orders/{orderId}/items`, `/sends/available-*`, `/treatments/available-technicians` |
| Paginator (`{current_page, data, last_page, total, ...}`) | `/treatments`, `/services`, `/sends`, `/orders` |
| Objek telanjang | `/attendances/today`, `/sends/{id}`, `/sends/{id}/detail` |

Endpoint tulis mengembalikan `{"message": ..., "data": ...}`, dan **`data` di situ adalah
model mentah** — nama kolom apa adanya, bukan bentuk yang sama dengan endpoint baca. Contoh
paling menjebak: `GET /daily-notes` memakai `note`/`activities`, tapi `POST /daily-notes` dan
`PUT /daily-notes/{id}/toggle-status` mengembalikan `title`/`description`. Jangan mengurai
balasan tulis dengan parser balasan baca — muat ulang daftarnya.

Pemetaan kolom catatan harian: `title` ⇄ `note`, `description` ⇄ `activities`,
`users_id` ⇄ `user_id`, `projects_id` → `branch_name`.

### 3.6 Harga tidak terlihat oleh Teknisi & Kurir

`GET /orders/{orderId}/items` dan `GET /services` sekarang terbuka untuk jabatan lapangan,
tapi muatannya dipangkas berdasar jabatan pemegang token:

| Kunci | Admin / Admin Super | Teknisi / Kurir |
|---|---|---|
| `items[].price`, `items[].discount` | ada | **tidak ada** |
| `items[].treatments[].price` | ada | **tidak ada** |
| `services[].price`, `services[].hpp` | ada | **tidak ada** |

Aplikasi lapangan harus memperlakukan kunci-kunci itu sebagai *boleh tidak ada*, bukan
sebagai nol.

Angka rupiah yang **tetap** terlihat kurir, karena memang dibutuhkan saat serah terima:
`GET /sends/{id}/detail` → `total_price`, `total_paid`, `credit`, `payment_status`. Juga
`GET /sends/pickup-waiting-list` → `total_price` dan `GET /sends/delivery-waiting-list` →
`price`/`discount` (peninggalan; jangan ditampilkan di layar kurir).

⚠️ **`payment_status` bisa berbohong.** `orders.total_price` boleh `NULL` (pesanan portal
pelanggan lahir tanpa harga — ditentukan setelah barang diperiksa), dan server memakai
`?? 0`. Akibatnya pesanan tanpa harga menghasilkan `credit = 0` dan dilaporkan
**`"paid"`**. Sampai diperbaiki di backend, perlakukan `total_price` kosong/nol sebagai
"harga belum ditentukan", bukan sebagai lunas.

### 3.7 Absen pulang bisa ditolak karena catatan harian

`POST /attendances/clock-out` sekarang menolak dengan **422** dalam dua keadaan baru:

| Keadaan | Pesan |
|---|---|
| Hari ini belum ada catatan harian sama sekali | `Anda belum membuat catatan harian hari ini. Buat catatan dulu sebelum absen pulang.` |
| Ada catatan yang statusnya belum selesai | `Masih ada N catatan harian hari ini yang belum diselesaikan. Selesaikan dulu sebelum absen pulang.` |

Berlaku untuk **Teknisi dan Kurir**. Admin dan Admin Super dibebaskan. Bendera `is_web` yang
dulu dipakai untuk melewati aturan ini **tidak ada lagi** — mengirimnya tidak berpengaruh.

Alur yang disarankan di aplikasi: sebelum tombol "Absen Pulang" aktif, panggil
`GET /daily-notes/today`; kalau `data` bernilai `null` arahkan ke form catatan, kalau
`data.status` bukan `1` arahkan ke `PUT /daily-notes/{id}/toggle-status`.

### 3.8 Nilai kode yang perlu dikunci ulang

| Kolom | Arti |
|---|---|
| `sends.type` | `0` = jemput, `1` = antar |
| `sends.status` | `0` = berjalan, `1` = selesai |
| `treatments.status` | `0` = dikerjakan, `1` = siap QC, `2` = lolos QC. Non-admin hanya boleh menaikkan 0 → 1 |
| `orders.status` | `0` pending, `1` proses, `2` selesai, `3` batal |
| `orders_items.status` | `0` baru, `2` siap antar, `3` sedang diantar, `4` selesai |
| `orders_items.type` | `1` = Tas (kelengkapan 7 butir), selain itu Sepatu (3 butir) |
| `issues.status` (catatan harian) | `0` belum selesai, `1` selesai |
| `attendances.type` | `0` masuk, `1` pulang |
| `attendances_absences.type` | `0` sakit, `1` izin, `2` cuti |
| `attendances_absences.is_approval` | `0` menunggu, `1` disetujui, `2` ditolak |

Kolom `checkbox` pada barang berisi `"true, false, true"` (dipisah koma-spasi). Untuk kurir,
jangan mengurai sendiri — pakai `kelengkapan` di `GET /sends/{id}/detail` yang sudah berupa
`[{nama, ada}]` beserta labelnya.

---

## 4. Cabang (multi-tenant)

Semua data terfilter otomatis ke cabang pemilik token. Aplikasi tidak perlu — dan tidak bisa —
mengirim `projects_id`. Akun Admin Super melihat semua cabang; untuk berpindah ada
`POST /auth/switch-branch`, tapi jalur itu bukan untuk aplikasi lapangan.

---

## 5. Dua endpoint yang butuh perhatian ekstra saat porting

### 5.1 Jemput ulang

**Lama:** `POST /mobiles/reorder-send`, id pengiriman di badan permintaan.
**Baru:** `POST /sends/{id}/reorder`, id di jalur, badan kosong.

```jsonc
// 201
{
  "message": "Pesanan jemput ulang berhasil dibuat",
  "data": {
    "orders_id": 812, "order_code": "INV2026080014", "customers_id": 233,
    "sends_id": 1904, "users_id": 7, "date": 1754697600, "type": 0, "status": 0
  }
}
```

Perilakunya sama dengan yang lama: pelanggan ditelusuri dari pengiriman itu, dibuatkan pesanan
baru berstatus pending, lalu tugas jemput untuk kurir yang meminta. Bedanya:

- Kurirnya adalah pemegang token. `users_id` di badan diabaikan.
- Kode pesanan dari generator yang sama dengan layar admin — tidak ada nomor invoice kembar.
- Order dan tugas jemput dibuat dalam satu transaksi.
- **422** kalau pengiriman itu tidak terhubung ke pelanggan mana pun.

### 5.2 Menyimpan barang dari lapangan

`POST /orders/{orderId}/items`. Untuk Teknisi/Kurir, kolom harga **tidak perlu dikirim dan
akan diabaikan kalau dikirim**:

- `price` barang dihitung server dari jumlah harga master layanan yang dipilih.
- `discount` dipaksa `0`.
- `services[].price` diambil dari master layanan.

Yang perlu dikirim aplikasi lapangan: `name`, `type`, `photo` (data-URL), `note`, `checkbox`,
dan `services[].services_id`. Sertakan `id` untuk mengubah barang yang sudah ada.

Untuk admin tidak ada perubahan: `price` dan `services[].price` tetap wajib dan tetap dihormati.
