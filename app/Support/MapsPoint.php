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
