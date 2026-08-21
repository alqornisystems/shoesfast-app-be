<?php

namespace App\Support;

use App\Models\Holiday;

/**
 * Hari kerja layanan: kapan kurir berangkat dan kapan barang bisa diambil.
 *
 * Minggu tutup, dan tanggal merah nasional juga. Keduanya dibaca dari sumber yang
 * sama dengan kalender presensi — libur yang membuat teknisi tidak masuk adalah
 * libur yang sama yang membuat kurir tidak berangkat, dan menuliskannya dua kali
 * berarti suatu saat keduanya berbeda.
 *
 * Sabtu MASUK. Bengkelnya buka Sabtu, dan memblokirnya cuma memindahkan pekerjaan
 * ke Senin tanpa alasan.
 */
class ServiceDay
{
    /** Paling jauh dicari saat menawarkan tanggal pengganti. */
    private const CARI_MAKS = 14;

    /** Kunci memo di container — lihat daftarLibur(). */
    private const MEMO = 'shoesfast.service_day.holidays';

    public static function isOpen(int $unix): bool
    {
        return self::alasanTutup($unix) === null;
    }

    /** Nama harinya kalau tutup — dipakai jadi pesan, null kalau buka. */
    public static function closedReason(int $unix): ?string
    {
        return self::alasanTutup($unix);
    }

    /** Tanggal buka pertama pada atau setelah $unix. */
    public static function nextOpen(int $unix): int
    {
        for ($i = 0; $i < self::CARI_MAKS; $i++) {
            $kandidat = $unix + ($i * 86400);

            if (self::isOpen($kandidat)) {
                return $kandidat;
            }
        }

        // Dua pekan penuh tanpa hari buka tidak mungkin terjadi kecuali tabel liburnya
        // salah isi. Mengembalikan tanggal asalnya lebih baik daripada melempar galat
        // di tengah pembuatan pesanan.
        return $unix;
    }

    /**
     * Tanggal tutup dalam rentang tertentu, untuk dikirim ke portal supaya kalendernya
     * bisa mematikan tanggal yang sama — bukan menebak aturannya sendiri.
     *
     * @return list<array{date: string, reason: string}>
     */
    public static function closedDates(int $dari, int $hari = 60): array
    {
        $hasil = [];

        for ($i = 0; $i < $hari; $i++) {
            $t = $dari + ($i * 86400);
            $alasan = self::alasanTutup($t);

            if ($alasan !== null) {
                $hasil[] = ['date' => date('Y-m-d', $t), 'reason' => $alasan];
            }
        }

        return $hasil;
    }

    private static function alasanTutup(int $unix): ?string
    {
        // 7 = Minggu. Sabtu (6) tetap hari kerja.
        if (date('N', $unix) === '7') {
            return 'Minggu libur';
        }

        return self::daftarLibur()[date('Y-m-d', $unix)] ?? null;
    }

    /**
     * @return array<string, string>
     *
     * Dimemo di container, BUKAN di properti statis. Properti statis hidup selama
     * proses PHP-nya hidup: di bawah Octane ia menyimpan daftar libur tahun lalu
     * sampai worker-nya di-restart, dan di dalam test ia membocorkan hasil satu
     * kasus ke kasus berikutnya. Container dibuat ulang tiap permintaan dan tiap
     * test, jadi masa hidupnya persis sepanjang yang dibutuhkan.
     */
    private static function daftarLibur(): array
    {
        if (app()->bound(self::MEMO)) {
            return app()->make(self::MEMO);
        }

        $libur = [];

        // Libur seluruh perusahaan (projects_id null) dan libur cabang mana pun. Portal
        // pelanggan tidak punya cabang aktif seperti panel staf, dan pelanggan yang
        // datang ke cabang yang tutup tidak terbantu oleh libur cabang lain yang
        // kebetulan buka.
        foreach (Holiday::withoutBranchScope()->where('is_deleted', 0)->get() as $baris) {
            $libur[date('Y-m-d', (int) $baris->date)] = (string) $baris->name;
        }

        app()->instance(self::MEMO, $libur);

        return $libur;
    }
}
