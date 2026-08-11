# Jawaban untuk tim mobile — `PERTANYAAN-BACKEND.md`

Menjawab lima pertanyaan dari aplikasi `shoesfastind`.

> **Cakupan jawaban.** Semua jawaban di bawah mengacu ke **`shoesfast-app-be`** (Laravel 12,
> `https://systems.shoesfast.id`) — backend tujuan migrasi. API lama yang dipakai aplikasi
> sekarang (`https://app.shoesfast.id/api/mobiles/*`, Slim) **tidak ada di ruang kerja ini**,
> jadi pertanyaan yang menyangkut perilaku produksi saat ini ditandai eksplisit dan tidak
> dijawab dengan tebakan.
>
> Jalur dan bentuk data di dokumen ini dikutip dari kode, bukan dari ingatan. Setiap klaim
> menyebutkan berkas dan barisnya.
>
> Baca bersama [`mobile-api-migration.md`](./mobile-api-migration.md) — di sana ada pemetaan
> jalur lama → baru dan daftar perubahan bentuk data yang akan memecahkan aplikasi.

**Satu hal yang menyentuh kelima jawaban:** backend baru **tidak memakai amplop
`{status_code, message, data}`**. Statusnya di HTTP status code, badannya `{"message": ...}`
atau `{"data": ...}`. Parser aplikasi harus disesuaikan sebelum apa pun di bawah ini berguna.
Rinciannya di migrasi §3.4 dan §3.5.

---

## 1. [MENAHAN RILIS] Endpoint verifikasi PIN absensi

### Ringkas

| Pertanyaan | Jawaban |
|---|---|
| Opsi A atau B? | **B** |
| PIN = password login, atau PIN terpisah 6 digit? | **Password login.** Tidak ada PIN terpisah untuk akun staf |
| Ada rate limit? | **Belum ada di endpoint absensi.** Harus ditambahkan bersama fitur ini |
| Endpointnya sudah ada? | **Belum.** Opsi mana pun berarti pekerjaan backend baru |

### PIN itu password login — tidak ada yang lain

Tabel `users` hanya punya kolom `password varchar(255)`. **Tidak ada kolom `pin`.**

```
mysql> SHOW COLUMNS FROM users;
name      varchar(100)   NO
password  varchar(255)   NO
roles_id  int(11)        YES
```

Kalau kalian pernah melihat "PIN 6 digit" di kode backend, itu milik tabel **`customers`**
(portal pelanggan, guard `auth:customer`) — entitas yang sama sekali berbeda dari staf.
Jangan dipakai sebagai acuan.

Verifikasinya nanti lewat `AuthController::checkPassword()`
([`AuthController.php`](../app/Http/Controllers/Api/AuthController.php)):

```php
if (str_starts_with($hashed, '$2y$') || str_starts_with($hashed, '$2a$')) {
    return Hash::check($plain, $hashed);
}
return hash('sha1', $plain) === $hashed;   // akun lama
```

Artinya: server bisa memverifikasi PIN tanpa perubahan skema apa pun. Yang belum ada cuma
jalurnya.

> Pesan login sekarang sudah menyebut input ini "PIN" (`"Nomor telepon atau PIN salah."`)
> meski kolomnya bernama `password`. Jadi istilah "PIN" di aplikasi tetap konsisten dengan
> yang dilihat pengguna.

### Kenapa Opsi B

Alasan kalian sendiri sudah benar (tidak ada celah antara verifikasi dan pencatatan), dan ada
satu alasan tambahan dari bentuk endpoint barunya: **absensi di backend baru tidak lagi
menerima `users_id`**. Identitas diambil dari token. Jadi Opsi A akan memaksa membuat endpoint
yang menerima `users_id` — mundur dari desain yang sudah dibereskan.

Payload absensi sekarang ([`AttendanceController.php:57-61`](../app/Http/Controllers/Api/AttendanceController.php#L57)):

```php
$request->validate([
    'latitude'  => ['required', 'numeric', 'between:-90,90'],
    'longitude' => ['required', 'numeric', 'between:-180,180'],
    'is_wfa'    => ['nullable', 'boolean'],
]);
```

Usulan kontrak final:

```
POST /api/attendances/clock-in     (dan /clock-out)
Authorization: Bearer <token>
Accept: application/json

{
  "pin":       "123456",
  "latitude":  -7.966612,
  "longitude": 112.632632,
  "is_wfa":    false
}
```

```jsonc
// 401 — PIN salah
{ "message": "PIN tidak sesuai." }

// 429 — terlalu sering
{ "message": "Terlalu banyak percobaan. Coba lagi dalam N detik." }
```

### Rate limit: belum ada, dan itu penting

Yang ada sekarang **hanya di login** ([`routes/api.php:58`](../routes/api.php#L58)):

```php
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1');
```

Endpoint absensi **tidak punya throttle sama sekali**. Kalau PIN masuk ke sana tanpa
pembatas, endpoint itu jadi tempat menebak password 6 digit tanpa batas — lebih longgar
daripada login. Usul: `throttle:5,1` per pengguna pada clock-in/clock-out, dan pastikan
aplikasi menangani **429**.

### "Apakah verifikasi PIN memang perlu?"

Menurut kami **perlu, tapi bukan sebagai lapisan keamanan.** Token sudah membuktikan
*akunnya*; PIN membuktikan *orangnya* — gunanya di HP yang ditinggal tidak terkunci atau
dipegang bergantian, supaya tidak ada yang mengabsenkan rekannya. Itu kontrol kepegawaian,
bukan kontrol akses.

Keputusannya ada di kebijakan HRD, bukan di teknis. Kalau HRD menganggap satu perangkat =
satu orang sudah cukup, hapus saja sisa kodenya — backend tidak akan kehilangan apa pun.

### Sekalian: tiga perubahan lain di absensi yang menahan rilis juga

Bukan bagian pertanyaan kalian, tapi kalau tidak ditangani, absensi akan gagal setelah pindah
ke backend baru:

1. **`users_id` tidak diterima lagi** — di endpoint mana pun. Diambil dari token.
2. **`latitude`/`longitude` wajib**, dan ada **radius 1 km** dari titik cabang. Di luar itu →
   422 dengan `distance` dan `max_distance`. Lolos hanya kalau `is_wfa: true`.
   ([`AttendanceController.php:116`](../app/Http/Controllers/Api/AttendanceController.php#L116))
3. **Absen pulang bisa ditolak karena catatan harian.** Untuk **Teknisi & Kurir**: hari tanpa
   catatan → 422; ada catatan belum selesai → 422. Bendera `is_web` yang dulu melewati aturan
   ini **sudah tidak ada**. ([`AttendanceController.php:210-229`](../app/Http/Controllers/Api/AttendanceController.php#L210))

---

## 2. Endpoint daftar absensi per bulan (layar Kehadiran)

### Sudah ada, tapi belum cukup

**`GET /api/attendances` sudah ada dan sudah terdokumentasi** di migrasi §1. Yang belum ada
adalah *kategori status per tanggal* yang kalian butuhkan.

| Pertanyaan | Jawaban |
|---|---|
| Sudah ada atau perlu dibuat? | Endpointnya ada; **kategorinya belum**. Perlu penambahan |
| Parameter periode | **`start_date` + `end_date`** (string tanggal), bukan `month`/`year` |
| Daftar nilai `status` | **Belum ada sama sekali.** Backend tidak pernah mengeluarkan kata "masuk/alpa/izin/libur/terlambat" |
| Hari tanpa data | **Tidak masuk daftar.** Hanya tanggal yang punya baris absensi yang muncul |

Bentuk balasannya sekarang
([`AttendanceController.php:352-368`](../app/Http/Controllers/Api/AttendanceController.php#L352)):

```jsonc
{
  "data": [
    {
      "date": "2026-08-04",              // string "Y-m-d"
      "user_id": 7,
      "user_name": "Budi",
      "clock_in":  { "time": 1786222680, "is_wfa": 0 },   // unix DETIK, bukan "07:58"
      "clock_out": { "time": 1786255320, "is_wfa": 0 },
      "duration": 32640                   // detik
    }
  ]
}
```

⚠️ Satu balasan memuat **dua bentuk waktu sekaligus**: `date` string, `clock_in.time` unix.
Jangan dipakai satu parser.

Catatan hak akses: default hanya absensi diri sendiri. `report_mode=true` dan `user_id`
**diabaikan** untuk non-admin — jadi aman, tapi juga berarti aplikasi lapangan tidak bisa
menampilkan kalender orang lain.

### Empat kategori kalian datang dari empat sumber berbeda

| Legenda | Sumber sekarang | Status |
|---|---|---|
| **masuk** | `GET /attendances` → ada `clock_in` | ✅ ada |
| **terlambat** | Aturannya ada, tapi **tidak di endpoint ini** — lihat bawah | ⚠️ tidak terjangkau |
| **izin** | `GET /absences` → `is_approval = 1` | ✅ ada, tapi terpisah |
| **libur** | `GET /holidays?start_date&end_date` → `date` (unix) | ✅ ada, tapi terpisah |
| **alpa** | **tidak ada di mana pun** — hasil turunan | ❌ belum ada yang menghitung |

"Alpa" = hari kerja, bukan libur, tidak ada absensi, tidak ada izin disetujui. Harus diputuskan
siapa yang menghitung. Kalau diserahkan ke aplikasi, aplikasi harus tahu jadwal hari kerja —
dan **jadwal hari kerja tidak ada di backend**, yang ada cuma tabel `holidays` (tanggal merah).
Akhir pekan tidak tercatat di mana pun.

### Soal "terlambat": aturannya ada, tapi kalian tidak bisa memanggilnya

Perhitungan telat sudah ada di
[`ReportController.php:1694-1699`](../app/Http/Controllers/Api/ReportController.php#L1694):

```php
$isLate = date('H:i', $clockIn->clock) > '08:00';           // telat
$isEarlyDeparture = date('H:i', $clockOut->clock) < '17:00'; // pulang cepat
```

Tapi endpointnya `GET /reports/attendance`, dan jalur itu dikunci jabatan
([`routes/api.php:393`](../routes/api.php#L393)):

```php
Route::middleware('role:Admin Super,Admin,HRD')->group(function () {
    Route::get('attendance', [ReportController::class, 'attendance']);
});
```

**Teknisi dan Kurir akan dapat 403.** Jadi aplikasi lapangan tidak punya jalan ke penanda telat.

Ambang **08:00 dan 17:00 di-hardcode** — tidak per cabang, tidak per jabatan, tidak ada di
tabel pengaturan. Kalau jam kerja tidak seragam, ini perlu dibicarakan terpisah.

### Yang kami usulkan

**Satu endpoint gabungan** — kalender butuh empat sumber, dan menyatukannya di klien berarti
tiga panggilan plus aturan bisnis (alpa, hari kerja) yang bocor ke aplikasi.

```
GET /api/attendances/calendar?start_date=2026-08-01&end_date=2026-08-31
Authorization: Bearer <token>
```

```jsonc
{
  "data": [
    { "date": "2026-08-01", "status": "libur", "keterangan": "Hari Kemerdekaan" },
    { "date": "2026-08-04", "status": "masuk",     "clock_in": 1786222680, "clock_out": 1786255320 },
    { "date": "2026-08-05", "status": "terlambat", "clock_in": 1786224660, "clock_out": 1786255200 },
    { "date": "2026-08-06", "status": "izin",  "keterangan": "Sakit" },
    { "date": "2026-08-07", "status": "alpa" }
  ]
}
```

Tiga hal yang perlu diputuskan bersama sebelum ini dibuat:

1. **Waktu: unix atau `"HH:mm"`?** Contoh di atas pakai unix supaya seragam dengan seluruh
   backend (migrasi §3.1). Kalau kalender lebih enak dengan `"07:58"`, bilang — tapi pilih
   satu, jangan campur.
2. **`izin` dipecah atau tidak?** Backend membedakan `type` **0 = Sakit, 1 = Izin, 2 = Cuti**.
   Legenda kalian cuma punya satu "izin". Digabung, atau legendanya ditambah?
3. **Hari yang belum lewat.** Usul kami: **tidak dikirim sama sekali** — biar aplikasi tidak
   perlu membedakan "belum terjadi" dari "alpa". Hari ini dan hari depan tidak pernah "alpa".

Kalau endpoint gabungan dianggap terlalu besar untuk sekarang, jalan minimum:
tambahkan `is_late` ke `GET /attendances` supaya kategori "terlambat" terjangkau tanpa
`/reports/*`, lalu aplikasi menggabung sendiri dengan `/absences` dan `/holidays`.

---

## 3. Format tanggal pada pengajuan izin

### `yyyy-MM-dd` sudah benar

Validasi + pemrosesan
([`AttendanceController.php:426-437`](../app/Http/Controllers/Api/AttendanceController.php#L426)):

```php
'date_start' => ['required', 'date'],
'date_end'   => ['required', 'date', 'after_or_equal:date_start'],

$dateStart = strtotime($request->input('date_start'));
$dateEnd   = strtotime($request->input('date_end') . ' 23:59:59');
$totalDays = floor(($dateEnd - $dateStart) / 86400) + 1;
```

Zona waktu aplikasi `Asia/Jakarta` ([`config/app.php:82`](../config/app.php#L82)), jadi
`"2026-08-09"` jadi tengah malam WIB. `date_end` **inklusif** — server menambahkan `23:59:59`
sendiri, jangan ditambahkan lagi dari aplikasi.

### Perbaikan kalian bukan sekadar kosmetik — format lama merusak barisnya

Kami uji langsung dengan PHP yang sama:

```
input=2026-08-09                date_start=1786226400   date_end=1786312799
input=2026-08-09 10:20:30.123   date_start=1786263630   date_end=false
```

`date_end` menjadi **`false`**, karena `strtotime("2026-08-09 10:20:30.123 23:59:59")` tidak
bisa diurai. Masuk ke kolom `int` sebagai **`0`**. Akibat berantainya:

| Akibat | Rincian |
|---|---|
| `total_days` jadi omong kosong | `floor((0 − 1786263630) / 86400) + 1` ≈ **−20.674 hari** |
| **Izin yang disetujui berhenti memblokir absensi** | Penjaga di clock-in adalah `date_start <= today AND date_end >= today`. Dengan `date_end = 0`, syarat kedua tidak pernah benar |
| Hari pertama meleset juga | `date_start` mendarat di 10:20, bukan tengah malam, jadi `date_start <= today` gagal di hari pertamanya |

Jadi izin yang tampak "disetujui" di layar tetap membiarkan orangnya absen — gejalanya senyap,
tidak ada error di mana pun.

### Baris rusak: nol di basis data `systems`

```sql
SELECT COUNT(*) total,
       SUM(date_end = 0 OR date_end IS NULL) date_end_rusak,
       SUM(total_days < 0)                   total_days_negatif
FROM attendances_absences;
```

```
total  date_end_rusak  total_days_negatif
46     0               0
```

Sebaran jamnya bersih semua:

```
jam_start   jam_end     baris
00:00:00    23:59:00    44     ← ditulis sistem lama
00:00:00    23:59:59     2     ← ditulis Laravel ini
```

Selisih `23:59:00` vs `23:59:59` cuma 59 detik dan tidak berdampak.

⚠️ **Tapi ini basis data `systems`, bukan basis data yang ditulis aplikasi mobile.** Aplikasi
kalian sekarang menembak `app.shoesfast.id`, dan basis data itu tidak ada di ruang kerja ini —
jadi **kami tidak bisa menyatakan data izin dari mobile bersih.** Jalankan kueri yang sama di
sana; nama tabelnya `attendances_absences` (jamak-jamak, bukan `attendance_absences`).

---

## 4. Nilai field `status` pada daftar pengiriman (kurir)

### Perbaikan kalian benar, tapi premisnya keliru — `status` bukan status pembayaran

`sends.status` bertipe `tinyint(1)` dan hanya menerima **0 atau 1**
([`SendController.php:160`](../app/Http/Controllers/Api/SendController.php#L160)):

```php
'status' => 'nullable|integer|in:0,1',
```

```
mysql> SHOW COLUMNS FROM sends LIKE 'status';
status  tinyint(1)  NO  0
```

| Nilai | Arti |
|---|---|
| `0` | Tugas kirim/jemput **berjalan** |
| `1` | Tugas **selesai** |

Itu **kemajuan tugas kurir**, bukan lunas/belum. Tidak ada "Lunas", "Batal", "Pending", atau
"Refund" — dan tidak akan ada, karena kolomnya integer 0/1.

Perbandingan `status == "Lunas"` di aplikasi lama membandingkan dua hal yang memang tidak
berhubungan. Bagus sudah dibuang.

### `total_pembayaran` dan `nominal_pembayaran` tidak ada di backend baru

Kedua nama itu peninggalan API lama. Penggantinya ada di
`GET /api/sends/{id}/detail` ([`SendController.php:836-842`](../app/Http/Controllers/Api/SendController.php#L836)):

```jsonc
{
  "total_price":    250000,
  "total_paid":     100000,
  "credit":         150000,
  "payment_status": "partial"   // "paid" | "partial" | "unpaid"
}
```

**Pakai `payment_status` langsung; jangan hitung sendiri lagi.** Rumusnya disalin dari
`PaymentController` supaya tidak pernah berselisih dengan layar pembayaran — kalau aplikasi
menghitung ulang, selisih itu justru diciptakan kembali.

### Jawaban untuk pertanyaan null kalian: **ya, ada jebakannya, dan persis seperti dugaan kalian**

```
mysql> SHOW COLUMNS FROM orders LIKE 'total_price';
total_price  int(11)  YES   NULL      ← boleh NULL

mysql> SHOW COLUMNS FROM payments LIKE 'nominal';
nominal      int(11)  NO              ← tidak pernah NULL
```

Backend melakukan `$totalPrice = $order->total_price ?? 0;`. Jadi:

> **Pesanan yang harganya belum ditentukan (`total_price = NULL`) menghasilkan `credit = 0`
> dan dilaporkan `payment_status: "paid"`.**

Persis kekhawatiran kalian, dan itu terjadi di sisi server — tidak bisa ditambal di aplikasi.
Pesanan portal pelanggan memang lahir tanpa harga (harga ditentukan setelah barang diperiksa),
jadi kasus ini nyata, bukan teoretis. **Perlu diperbaiki di backend**: `total_price` null
seharusnya `payment_status: "unpaid"` atau nilai ketiga semacam `"belum_ditentukan"`.

`total_paid` aman — `payments.nominal` NOT NULL, dan `sum()` tanpa baris mengembalikan 0.

### Satu lagi: daftar tidak membawa angka pembayaran sama sekali

`GET /sends`, `/sends/in-progress`, `/sends/history` **tidak memuat** `total_price`,
`credit`, maupun `payment_status` — hanya `id`, `date`, `status`, `type`, `user`, `order`,
`order_item`, `project_name`, `created_at`
([`SendController.php:81-106`](../app/Http/Controllers/Api/SendController.php#L81)).

Angka pembayaran hanya ada di `GET /sends/{id}/detail`. Jadi kalau layar daftar kurir mau
menandai lunas/belum per baris, pilihannya: backend menambahkan `payment_status` ke daftar
(disarankan), atau aplikasi memanggil detail satu per satu (N+1, jangan).

---

## 5. URL staging

**Tidak ada environment staging.** Yang ada hanya produksi.

- `.github/workflows/deploy.yml` — satu workflow, satu target FTP, pemicunya
  `on: push: branches: [ master ]`. Tidak ada job atau secret kedua untuk staging.
- `.env.production.bak` → `APP_URL=https://systems.shoesfast.id`

Jadi untuk sekarang: **beri penanda jelas di kode bahwa `staging` bukan lingkungan terpisah**,
seperti rencana kalian. Menyamakannya diam-diam dengan produksi adalah keadaan paling
berbahaya dari keduanya.

Sekalian dicatat: setelah migrasi, base URL berubah dari
`https://app.shoesfast.id/api/` menjadi **`https://systems.shoesfast.id/api/`**, dan
prefix `mobiles/` **hilang** — jalurnya langsung `attendances/clock-in`, `sends/history`, dst.

---

## Ringkasan

| # | Topik | Jawaban singkat | Perlu kerja backend | Menahan rilis |
|---|---|---|---|---|
| 1 | Verifikasi PIN | **Opsi B.** PIN = password login. Tidak ada kolom `pin` untuk staf | **Ya** — endpoint + throttle | **Ya** |
| 2 | Kalender absensi | `GET /attendances` ada, kategori status belum. "Terlambat" terkunci di `/reports/*` (403 untuk kurir/teknisi). "Alpa" belum dihitung siapa pun | **Ya** — endpoint gabungan, atau minimal `is_late` | Tidak |
| 3 | Format tanggal izin | **`yyyy-MM-dd` benar.** Format lama membuat `date_end = 0` dan izin berhenti memblokir absensi | Tidak | Tidak |
| 4 | `status` pengiriman | `status` = kemajuan tugas (0/1), **bukan** pembayaran. Pakai `payment_status` dari `/sends/{id}/detail` | **Ya** — `total_price` NULL salah dilaporkan `"paid"`; daftar tidak membawa `payment_status` | Tidak |
| 5 | Staging | **Tidak ada.** Satu workflow, satu target, `master` → produksi | Tidak (infrastruktur) | Tidak |

### Yang perlu diputuskan tim backend

1. **Setujui kontrak PIN Opsi B** + `throttle:5,1` di clock-in/clock-out. — *menahan rilis*
2. **`orders.total_price` NULL dilaporkan `"paid"`.** Bug nyata, terjadi pada setiap pesanan
   portal pelanggan yang harganya belum ditentukan.
3. **Ambang telat 08:00 / pulang cepat 17:00 di-hardcode.** Konfirmasi apakah seragam untuk
   semua cabang dan jabatan.
4. **Tidak ada jadwal hari kerja di backend** — hanya tabel `holidays`. Tanpa itu "alpa" tidak
   bisa dihitung dengan benar oleh siapa pun.

### Yang perlu diperiksa tim mobile

1. **Amplop balasan berubah total.** Tidak ada `status_code`. Baca HTTP status code; `403`
   lama yang berarti "input salah" sekarang **`422`**. Migrasi §3.4.
2. **`users_id` tidak dikirim lagi di endpoint mana pun.** Migrasi §2.3.
3. **Jalankan kueri baris rusak `attendances_absences` di basis data `app.shoesfast.id`** —
   yang kami periksa basis data `systems`, dan di sana bersih.
4. **Satu sesi per akun**: login di perangkat kedua mementalkan perangkat pertama ke 401.
   Migrasi §2.4.
