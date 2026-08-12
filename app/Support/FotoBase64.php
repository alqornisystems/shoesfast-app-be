<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Menyimpan gambar data-URL ke storage publik dan mengembalikan JALUR RELATIF.
 *
 * Alasan keberadaannya: beberapa layar mengirim foto sebagai data URL lalu nilainya
 * ditulis apa adanya ke kolom `photo`. Untuk foto kamera 2 MB itu berarti ~2,7 juta
 * karakter masuk ke kolom TEXT (batas 65.535 byte) — MySQL memotongnya diam-diam dan
 * yang tersimpan adalah data URL rusak yang tidak akan pernah bisa dirender lagi.
 *
 * Nilai yang BUKAN data URL (jalur relatif atau URL absolut milik data lama) dibiarkan
 * lewat tanpa disentuh, sehingga aman dipasang di jalur yang sudah berjalan.
 */
class FotoBase64
{
    /**
     * @param  string  $nilai  data URL, jalur relatif, atau URL absolut
     * @param  string  $folder  folder tujuan di disk publik, mis. 'users'
     * @return string jalur relatif ('users/x.jpg') atau nilai asli kalau bukan data URL
     *
     * @throws \RuntimeException kalau data URL-nya tidak bisa didekode
     */
    public static function simpan(string $nilai, string $folder): string
    {
        if (! preg_match('/^data:image\/(\w+);base64,/', $nilai, $cocok)) {
            return $nilai;
        }

        $jenis = strtolower($cocok[1]) === 'jpeg' ? 'jpg' : strtolower($cocok[1]);
        // '+' berubah jadi spasi kalau data URL sempat lewat query string.
        $base64 = str_replace(' ', '+', substr($nilai, strpos($nilai, ',') + 1));
        $isi = base64_decode($base64, true);

        if ($isi === false) {
            throw new \RuntimeException('Data gambar tidak bisa dibaca.');
        }

        $jalur = $folder.'/'.$folder.'_'.time().'_'.uniqid().'.'.$jenis;
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
}
