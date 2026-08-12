<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Menyimpan gambar data-URL ke storage publik dan mengembalikan JALUR RELATIF.
 *
 * Dua hal dikerjakan di sini, dan keduanya harus di satu tempat supaya tidak ada jalur
 * unggah yang diam-diam berbeda perlakuannya:
 *
 * 1. Data URL didekode ke berkas. Sebelumnya sebagian jalur menulis base64 apa adanya ke
 *    kolom — untuk foto 2 MB itu ~2,7 juta karakter masuk ke kolom TEXT berbatas 65.535
 *    byte, dipotong MySQL tanpa galat, dan hasilnya berkas rusak yang tidak pernah bisa
 *    dibuka lagi.
 * 2. Gambar dilebarkan-paskan ke 1080px. Foto kamera HP hari ini 3000-4000px dan 3-5 MB;
 *    yang dibuka kurir di layar 6 inci lewat kuota sendiri tidak butuh sebesar itu.
 */
class FotoBase64
{
    /** Lebar maksimum. Tinggi mengikuti rasio aslinya. */
    public const LEBAR_MAKS = 1080;

    /** Mutu JPEG hasil re-encode. 82 adalah titik di mana selisihnya tidak lagi terlihat mata. */
    private const MUTU_JPEG = 82;

    /**
     * Batas jumlah piksel yang mau didekode. GD memuat seluruh bitmap ke memori —
     * sekitar 4 byte per piksel — jadi gambar 50 MP menuntut ~200 MB dan akan
     * mematikan proses PHP alih-alih mengembalikan galat yang bisa dibaca.
     */
    private const PIKSEL_MAKS = 40_000_000;

    /**
     * @param  string  $nilai  data URL, jalur relatif, atau URL absolut
     * @param  string  $folder  folder tujuan di disk publik, mis. 'users'
     * @return string jalur relatif ('users/x.jpg') atau nilai asli kalau bukan data URL
     *
     * @throws \RuntimeException kalau data URL-nya tidak bisa didekode
     */
    public static function simpan(string $nilai, string $folder, ?string $namaDasar = null): string
    {
        if (! preg_match('/^data:image\/(\w+);base64,/', $nilai, $cocok)) {
            // Jalur relatif atau URL absolut milik data lama dibiarkan lewat, sehingga
            // helper ini aman dipasang di jalur yang sudah berjalan.
            return $nilai;
        }

        $jenis = strtolower($cocok[1]) === 'jpeg' ? 'jpg' : strtolower($cocok[1]);
        // '+' berubah jadi spasi kalau data URL sempat lewat query string.
        $base64 = str_replace(' ', '+', substr($nilai, strpos($nilai, ',') + 1));
        $isi = base64_decode($base64, true);

        if ($isi === false) {
            throw new \RuntimeException('Data gambar tidak bisa dibaca.');
        }

        [$isi, $jenis] = self::kecilkan($isi, $jenis);

        $nama = ($namaDasar ?: $folder.'_'.time().'_'.uniqid()).'.'.$jenis;
        $jalur = $folder.'/'.$nama;

        Storage::disk('public')->put($jalur, $isi);

        return $jalur;
    }

    /** Kebalikannya: jalur apa pun jadi URL yang bisa dipasang di <img>. */
    public static function url(?string $nilai): ?string
    {
        if (empty($nilai)) {
            return null;
        }

        return filter_var($nilai, FILTER_VALIDATE_URL) ? $nilai : asset('storage/'.$nilai);
    }

    /**
     * Lebarkan-paskan ke LEBAR_MAKS, tinggi mengikuti rasio.
     *
     * Gambar yang sudah lebih kecil TIDAK dibesarkan — membesarkan hanya menambah berkas
     * tanpa menambah satu pun detail, dan hasilnya justru terlihat lebih buram.
     *
     * Mengembalikan [isi, jenis] karena jenisnya bisa berubah: gambar yang tidak punya
     * lapisan transparan dikeluarkan sebagai JPEG walau masuk sebagai PNG — foto kamera
     * yang tersimpan sebagai PNG bisa sepuluh kali lebih besar pada mutu yang sama mata.
     *
     * @return array{0: string, 1: string}
     */
    private static function kecilkan(string $isi, string $jenis): array
    {
        // GIF sering animasi, dan GD hanya bisa menyimpan bingkai pertamanya — mengecilkan
        // berarti diam-diam membuang animasinya. Dilewatkan apa adanya.
        if ($jenis === 'gif' || ! extension_loaded('gd')) {
            return [$isi, $jenis];
        }

        $ukuran = @getimagesizefromstring($isi);

        if ($ukuran === false) {
            // Bukan gambar yang bisa dibaca GD. Disimpan apa adanya; menolak di sini
            // berarti pengajuan izin gagal total hanya karena lampirannya aneh.
            return [$isi, $jenis];
        }

        [$lebar, $tinggi] = $ukuran;

        if ($lebar <= 0 || $tinggi <= 0 || $lebar * $tinggi > self::PIKSEL_MAKS) {
            return [$isi, $jenis];
        }

        $sumber = @imagecreatefromstring($isi);

        if ($sumber === false) {
            return [$isi, $jenis];
        }

        $sumber = self::luruskanOrientasi($sumber, $isi);
        $lebar = imagesx($sumber);
        $tinggi = imagesy($sumber);

        $transparan = self::punyaTransparansi($sumber, $jenis);
        $perluKecil = $lebar > self::LEBAR_MAKS;

        // Sudah cukup kecil DAN transparan: tidak ada yang bisa diperbaiki tanpa merusak.
        if (! $perluKecil && $transparan) {
            imagedestroy($sumber);

            return [$isi, $jenis];
        }

        if ($perluKecil) {
            $lebarBaru = self::LEBAR_MAKS;
            $tinggiBaru = max(1, (int) round($tinggi * (self::LEBAR_MAKS / $lebar)));
            $hasil = imagescale($sumber, $lebarBaru, $tinggiBaru);
            imagedestroy($sumber);

            if ($hasil === false) {
                return [$isi, $jenis];
            }
        } else {
            $hasil = $sumber;
        }

        ob_start();

        if ($transparan) {
            imagealphablending($hasil, false);
            imagesavealpha($hasil, true);
            imagepng($hasil, null, 8);
            $jenisBaru = 'png';
        } else {
            // Latar putih, bukan hitam: PNG transparan yang diratakan ke JPEG dengan latar
            // hitam menghasilkan foto yang tampak rusak.
            $rata = imagecreatetruecolor(imagesx($hasil), imagesy($hasil));
            imagefill($rata, 0, 0, imagecolorallocate($rata, 255, 255, 255));
            imagecopy($rata, $hasil, 0, 0, 0, 0, imagesx($hasil), imagesy($hasil));
            imagejpeg($rata, null, self::MUTU_JPEG);
            imagedestroy($rata);
            $jenisBaru = 'jpg';
        }

        $baru = ob_get_clean();
        imagedestroy($hasil);

        if ($baru === false || $baru === '') {
            return [$isi, $jenis];
        }

        // Kalau hasil "kecil" ternyata lebih besar dari aslinya (kerap terjadi pada gambar
        // kecil yang sudah termampatkan baik), pakai yang asli.
        if (! $perluKecil && strlen($baru) >= strlen($isi)) {
            return [$isi, $jenis];
        }

        return [$baru, $jenisBaru];
    }

    /**
     * Putar gambar mengikuti penanda EXIF.
     *
     * GD membuang seluruh metadata saat menyimpan, jadi tanpa langkah ini foto potret
     * dari HP — yang sebenarnya tersimpan mendatar plus penanda "putar 90°" — akan
     * muncul miring setelah diproses. Kurir yang memotret bukti serah terima secara
     * potret akan mendapati semua fotonya rebah.
     *
     * @param  \GdImage  $gambar
     * @return \GdImage
     */
    private static function luruskanOrientasi($gambar, string $isi)
    {
        if (! function_exists('exif_read_data')) {
            return $gambar;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($isi));
        } catch (\Throwable $e) {
            return $gambar;
        }

        $orientasi = $exif['Orientation'] ?? null;

        $derajat = match ($orientasi) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($derajat === 0) {
            return $gambar;
        }

        $diputar = imagerotate($gambar, $derajat, 0);

        if ($diputar === false) {
            return $gambar;
        }

        imagedestroy($gambar);

        return $diputar;
    }

    /**
     * Apakah gambar punya piksel tembus pandang.
     *
     * Diperiksa, bukan diasumsikan dari ekstensi: kebanyakan PNG dari kamera dan tangkapan
     * layar sepenuhnya buram, dan mempertahankannya sebagai PNG berarti berkasnya berkali
     * lipat lebih besar tanpa satu pun manfaat. Yang benar-benar transparan (logo, tanda
     * tangan) tetap PNG.
     *
     * @param  \GdImage  $gambar
     */
    private static function punyaTransparansi($gambar, string $jenis): bool
    {
        if (! in_array($jenis, ['png', 'webp'], true)) {
            return false;
        }

        $lebar = imagesx($gambar);
        $tinggi = imagesy($gambar);

        // Dipindai berjarak, bukan piksel per piksel: gambar 1080x1920 berarti dua juta
        // pembacaan, dan yang dicari cuma "ada atau tidak". Langkah 8 piksel sudah
        // menangkap area transparan sebesar apa pun yang berarti secara visual.
        $langkah = 8;

        for ($x = 0; $x < $lebar; $x += $langkah) {
            for ($y = 0; $y < $tinggi; $y += $langkah) {
                $alpha = (imagecolorat($gambar, $x, $y) >> 24) & 0x7F;

                if ($alpha > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
