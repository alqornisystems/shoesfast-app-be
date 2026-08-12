<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    /**
     * Bawaan sengaja "0.0.0", bukan angka yang kelihatan wajar.
     *
     * Setting::read() sengaja degradasi ke default kalau tabel settings belum
     * ada / baris belum diisi / query gagal. Di endpoint ini default itu jadi
     * gerbang paksa-update: kalau bawaannya lebih tinggi daripada versi yang
     * beredar di HP pengguna, SEMUA orang langsung terkunci di layar "perbarui
     * dulu" yang tidak bisa dilewati — outage total tanpa satu pun error di log.
     * "0.0.0" selalu lebih rendah dari versi apa pun yang terpasang, jadi
     * kegagalan baca hanya berarti gerbangnya mati, bukan aplikasinya mati.
     *
     * Jangan diganti angka "sekarang kan sudah 1.2.0" — begitu rilis backend
     * jalan sebelum baris settings-nya terisi, itu mengunci semua pengguna.
     */
    private const DEFAULT_VERSION = '0.0.0';

    // GET /api/app/version (publik, tanpa auth)
    public function show(): JsonResponse
    {
        // Daftar putih per kunci, sama seperti Api\Customer\SettingController:
        // tabel settings juga menyimpan kredensial WAHA, jadi endpoint publik ini
        // tidak boleh pernah membaca tabelnya secara borongan.
        $min = (string) Setting::read('app_min_version', self::DEFAULT_VERSION);

        return response()->json([
            'min_version' => $min,
            // Kalau latest belum diisi, samakan dengan min supaya aplikasi tidak
            // menampilkan ajakan update ke versi yang tidak pernah ada.
            'latest_version' => (string) Setting::read('app_latest_version', $min),
            // Null itu sah: tanpa URL, aplikasi menampilkan pesannya saja tanpa
            // tombol yang membuka halaman kosong.
            'store_url' => Setting::read('app_store_url'),
        ]);
    }
}
