# SQL perubahan database — jalankan di produksi

Skrip ini setara dengan `php artisan migrate` untuk seluruh perubahan skema yang menyertai
portal pelanggan dan aplikasi mobile staf. Dipakai kalau kamu lebih suka menjalankan SQL
langsung daripada artisan di server.

**Semuanya idempoten** (`IF NOT EXISTS`), jadi aman dijalankan berulang dan aman kalau
sebagian sudah pernah jalan. Sintaks `ADD COLUMN IF NOT EXISTS` adalah **MariaDB** — server
Shoesfast memakai MariaDB. Kalau suatu saat pindah ke MySQL asli, sintaks itu harus diganti
karena MySQL tidak mendukungnya.

Backup dulu:

```bash
mysqldump -u USER -p shoesfast > shoesfast-sebelum-migrasi.sql
```

---

## 1. Kolom portal pelanggan di `customers` dan `orders`

Setara migrasi `2026_07_28_000001_add_customer_portal_columns`.

```sql
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `pin` VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `pin_created_at` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `pin_created_ip` VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10,8) NULL,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11,8) NULL;

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `pickup_address` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `pickup_maps` TEXT NULL,
  ADD COLUMN IF NOT EXISTS `source` TINYINT(4) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `points_awarded` TINYINT(4) NOT NULL DEFAULT 0;
```

## 2. Tabel hadiah dan penukaran poin

Setara migrasi `2026_07_28_000002_create_rewards_tables`.

```sql
CREATE TABLE IF NOT EXISTS `rewards` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `projects_id` INT(11) NOT NULL DEFAULT 1,
  `name`        VARCHAR(100) NOT NULL,
  `type`        TINYINT(4) NOT NULL DEFAULT 0 COMMENT '0=layanan, 1=barang',
  `services_id` INT(11) NULL,
  `points_cost` INT(11) NOT NULL,
  `photo`       TEXT NULL,
  `is_active`   TINYINT(4) NOT NULL DEFAULT 1,
  `is_deleted`  TINYINT(4) NOT NULL DEFAULT 0,
  `created_at`  INT(11) NULL,
  `created_by`  INT(11) NULL,
  `modified_at` INT(11) NULL,
  `modified_by` INT(11) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reward_redemptions` (
  `id`           INT(11) NOT NULL AUTO_INCREMENT,
  `projects_id`  INT(11) NOT NULL DEFAULT 1,
  `customers_id` INT(11) NOT NULL,
  `rewards_id`   INT(11) NOT NULL,
  `code`         VARCHAR(20) NOT NULL,
  `points_spent` INT(11) NOT NULL,
  `status`       TINYINT(4) NOT NULL DEFAULT 0 COMMENT '0=menunggu diambil, 1=selesai',
  `date`         INT(11) NOT NULL,
  `is_deleted`   TINYINT(4) NOT NULL DEFAULT 0,
  `created_at`   INT(11) NULL,
  `created_by`   INT(11) NULL,
  `modified_at`  INT(11) NULL,
  `modified_by`  INT(11) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reward_redemptions_code_unique` (`code`),
  KEY `reward_redemptions_customers_id_index` (`customers_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## 3. Kolom alur tugas lapangan di `sends`

Setara migrasi `2026_08_13_000001_add_field_task_columns_to_sends_table`. Inilah yang
dibutuhkan endpoint berangkat / gagal / bukti serah terima.

```sql
ALTER TABLE `sends`
  ADD COLUMN IF NOT EXISTS `started_at`      INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `failed_at`       INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `reason_code`     VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS `fail_note`       TEXT NULL,
  ADD COLUMN IF NOT EXISTS `reschedule_date` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `proof_photo`     TEXT NULL,
  ADD COLUMN IF NOT EXISTS `receiver_name`   VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS `proof_latitude`  DECIMAL(10,8) NULL,
  ADD COLUMN IF NOT EXISTS `proof_longitude` DECIMAL(11,8) NULL,
  ADD COLUMN IF NOT EXISTS `proof_at`        INT(11) NULL;
```

Kolom `status` di `sends` **tidak berubah tipenya**, tapi artinya bertambah: `0` berjalan,
`1` selesai, dan sekarang `2` gagal. Tidak ada data lama yang perlu disentuh.

## 4. Tabel token perangkat FCM

Setara migrasi `2026_08_13_000002_create_device_tokens_table`.

```sql
CREATE TABLE IF NOT EXISTS `device_tokens` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `users_id`    INT(11) NOT NULL,
  `token`       VARCHAR(255) NOT NULL,
  `platform`    VARCHAR(20) NULL,
  `is_deleted`  INT(11) NOT NULL DEFAULT 0,
  `created_at`  INT(11) NULL,
  `modified_at` INT(11) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_tokens_token_unique` (`token`),
  KEY `device_tokens_users_id_index` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`token` sengaja UNIQUE: FCM memindahkan token ketika sebuah perangkat dipakai orang lain,
dan tanpa kunci unik notifikasi tugas akan ikut terkirim ke pemilik lama.

## 5. Jam mulai pengerjaan teknisi

Setara migrasi `2026_08_13_000003_add_started_at_to_treatments_table`. Dibutuhkan
`POST /api/treatments/{id}/start`.

```sql
ALTER TABLE `treatments`
  ADD COLUMN IF NOT EXISTS `started_at` INT(11) NULL;
```

Kolom baru, **bukan** memakai ulang `date_start`. Yang itu jadwal rencana: ia mengurutkan
waiting list sekaligus menjadi penyebut perhitungan `progress`, jadi menimpanya saat
teknisi menekan "mulai" akan menggeser antrean dan membuat progress melompat mundur ke nol.

## 6. Sesi pelacakan kurir di `sends`

Setara migrasi `2026_08_21_000001_add_tracking_columns_to_sends_table`. Dibutuhkan tautan
pelacakan yang dibuka pelanggan dari WhatsApp.

```sql
ALTER TABLE `sends`
  ADD COLUMN IF NOT EXISTS `tracking_token`      VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS `tracking_expires_at` INT(11) NULL,
  ADD COLUMN IF NOT EXISTS `courier_latitude`    DECIMAL(10,8) NULL,
  ADD COLUMN IF NOT EXISTS `courier_longitude`   DECIMAL(11,8) NULL,
  ADD COLUMN IF NOT EXISTS `courier_accuracy`    FLOAT NULL,
  ADD COLUMN IF NOT EXISTS `courier_position_at` INT(11) NULL;

-- Unik: satu token satu tugas, dan pencarian token dilakukan tiap kali halaman
-- pelacakan menyegarkan dirinya.
ALTER TABLE `sends`
  ADD UNIQUE INDEX IF NOT EXISTS `sends_tracking_token_unique` (`tracking_token`);
```

Yang disimpan hanya posisi **terakhir**, bukan jejak. Riwayat satu jam adalah peta
kebiasaan seseorang, dan tidak ada satu pun pertanyaan pelanggan yang membutuhkannya.
Keenam kolom ini dikosongkan lagi begitu tugasnya selesai atau gagal.

## 7. Baris `settings` untuk gerbang versi aplikasi

Bukan perubahan skema, tapi tanpa ini gerbang versi aplikasi mobile tidak melakukan apa-apa.
Nilai di bawah aman — `0.0.0` berarti gerbangnya mati dan tidak mengunci siapa pun.

```sql
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('app_min_version',    '0.0.0'),
  ('app_latest_version', '0.0.0'),
  ('app_store_url',      NULL);
```

Urutan menaikkannya nanti: isi `app_store_url` dan `app_latest_version` dulu, naikkan
`app_min_version` **terakhir** — setelah build barunya benar-benar ada di store. Menaikkan
lebih dulu langsung mengunci semua orang yang belum sempat memperbarui.

## 8. Pembayaran bisa menunjuk barang

Setara migrasi `2026_08_22_000001_add_orders_items_id_to_payments_table`. Dibutuhkan
aturan "barang boleh diambil satu per satu setelah tagihannya sendiri lunas".

```sql
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `orders_items_id` INT(11) NULL AFTER `orders_id`;

ALTER TABLE `payments`
  ADD INDEX IF NOT EXISTS `payments_orders_items_id_index` (`orders_items_id`);
```

Kolomnya **nullable dan memang akan tetap null untuk sebagian besar baris**. Pembayaran
lama tidak punya jawabannya, dan pembayaran yang melunasi seluruh pesanan sekaligus
memang tidak menunjuk barang mana pun. Keduanya tetap dibagi rata seperti sebelumnya —
tidak ada data lama yang perlu disentuh, dan tidak ada yang rusak kalau kolomnya
dibiarkan kosong selamanya.

Yang berubah hanya ini: begitu kasir mengisi kolomnya, tebakan berhenti dan angkanya
jadi fakta.

## 9. Rekening tujuan pembayaran di `projects`

Setara migrasi `2026_08_22_000002_add_bank_account_to_projects_table`. Dibutuhkan tombol
"Bayar sekarang" di portal pelanggan.

```sql
ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `bank_name`           VARCHAR(50)  NULL AFTER `whatsapp`,
  ADD COLUMN IF NOT EXISTS `bank_account_number` VARCHAR(50)  NULL AFTER `bank_name`,
  ADD COLUMN IF NOT EXISTS `bank_account_name`   VARCHAR(100) NULL AFTER `bank_account_number`;
```

Ditaruh di `projects`, bukan di `settings`, karena **nomor WhatsApp-nya sudah per
cabang** (kolom `projects.whatsapp`). Rekening yang global sementara nomor WA-nya per
cabang berarti pelanggan Surabaya diminta mentransfer ke rekening pusat lalu mengirim
buktinya ke nomor Surabaya.

Isi per cabang — **nilai di bawah contoh, ganti dengan yang sebenarnya**:

```sql
UPDATE `projects` SET
  `bank_name`           = 'BCA',
  `bank_account_number` = '1234567890',
  `bank_account_name`   = 'Shoesfast'
WHERE `id` = 1;
```

Selama salah satu dari tiga kolom itu kosong, tombol bayarnya **tidak muncul** di
portal. Itu perilaku yang benar: lebih baik tidak ada tombol daripada mengarahkan orang
ke nomor rekening yang salah.

`projects.whatsapp` harus format internasional tanpa `+` dan tanpa `0` di depan
(`6281334380934`) — itu yang diminta tautan `wa.me`. Data produksi sekarang sudah benar.

---

## Setelah dijalankan

Periksa hasilnya:

```sql
SHOW COLUMNS FROM `sends` LIKE 'proof_%';
SHOW COLUMNS FROM `treatments` LIKE 'started_at';
SHOW COLUMNS FROM `sends` LIKE 'tracking_%';
SHOW COLUMNS FROM `payments` LIKE 'orders_items_id';
SHOW TABLES LIKE 'device_tokens';
SELECT `key`, `value` FROM `settings` WHERE `key` LIKE 'app_%';
SELECT `id`, `name`, `whatsapp`, `bank_name`, `bank_account_number` FROM `projects` WHERE `is_deleted` = 0;
```

Lalu tandai migrasinya sudah jalan supaya `php artisan migrate` berikutnya tidak mencoba
mengulang — **hanya kalau kamu menjalankan SQL ini secara manual**, bukan lewat artisan:

```sql
INSERT IGNORE INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_07_28_000001_add_customer_portal_columns',        (SELECT COALESCE(MAX(b.batch),0)+1 FROM (SELECT batch FROM migrations) b)),
  ('2026_07_28_000002_create_rewards_tables',              (SELECT COALESCE(MAX(b.batch),0)   FROM (SELECT batch FROM migrations) b)),
  ('2026_08_13_000001_add_field_task_columns_to_sends_table', (SELECT COALESCE(MAX(b.batch),0) FROM (SELECT batch FROM migrations) b)),
  ('2026_08_13_000002_create_device_tokens_table',         (SELECT COALESCE(MAX(b.batch),0)   FROM (SELECT batch FROM migrations) b)),
  ('2026_08_13_000003_add_started_at_to_treatments_table',  (SELECT COALESCE(MAX(b.batch),0)   FROM (SELECT batch FROM migrations) b)),
  ('2026_08_21_000001_add_tracking_columns_to_sends_table', (SELECT COALESCE(MAX(b.batch),0)   FROM (SELECT batch FROM migrations) b)),
  ('2026_08_22_000001_add_orders_items_id_to_payments_table', (SELECT COALESCE(MAX(b.batch),0) FROM (SELECT batch FROM migrations) b)),
  ('2026_08_22_000002_add_bank_account_to_projects_table',    (SELECT COALESCE(MAX(b.batch),0) FROM (SELECT batch FROM migrations) b));
```

Sebenarnya kedelapan migrasi itu sendiri sudah dijaga `hasColumn`/`hasTable`, jadi
menjalankan `php artisan migrate` setelah SQL ini pun tidak akan merusak apa pun — ia hanya
akan melewati yang sudah ada.
