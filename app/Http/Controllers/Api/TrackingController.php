<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Send;
use App\Support\Base64Image;
use App\Support\Distance;
use Illuminate\Http\JsonResponse;

/**
 * Halaman pelacakan yang dibuka pelanggan dari tautan WhatsApp. Tanpa login.
 *
 * Seluruh isi balasan di sini adalah keputusan sadar tentang apa yang BOLEH dilihat orang
 * yang memegang tautan — dan tautan bisa diteruskan ke siapa saja. Yang sengaja TIDAK
 * dikirim, masing-masing dengan alasannya:
 *
 * - Nomor telepon kurir. Pelanggan sudah punya nomor toko; nomor pribadi karyawan yang
 *   tersebar lewat tautan publik tidak bisa ditarik kembali.
 * - Riwayat jejak. Hanya posisi terakhir — jejak satu jam adalah peta kebiasaan seseorang.
 * - Tugas lain kurir itu, jumlahnya sekalipun. "Anda pelanggan ke-7 hari ini" adalah
 *   informasi tentang bisnis, bukan tentang pengiriman ini.
 * - Nominal tagihan, kode pesanan, dan alamat tertulis. Tautan ini bisa diteruskan.
 */
class TrackingController extends Controller
{
    /** Posisi yang lebih tua dari ini tidak lagi disebut posisi kurir sekarang. */
    private const BASI_DETIK = 300;

    // GET /api/lacak/{token}
    public function show(string $token): JsonResponse
    {
        // withoutGlobalScopes: tautan ini dibuka tanpa sesi, jadi tidak ada cabang aktif
        // yang bisa dipakai branch scope untuk menyaring — tanpa ini setiap tautan
        // menjawab "tidak ditemukan".
        $send = Send::withoutGlobalScopes()
            ->with(['user', 'order.customer'])
            ->where('tracking_token', $token)
            ->where('is_deleted', 0)
            ->first();

        // Token dicabut saat tugas berakhir, jadi "tidak ketemu" dan "sudah selesai" tiba
        // di sini sebagai keadaan yang sama. Dijawab sebagai kedaluwarsa, bukan 404:
        // pelanggan yang membuka tautan lamanya berhak dapat kalimat, bukan galat.
        if (! $send) {
            return $this->berakhir('expired');
        }

        if ($send->tracking_expires_at !== null && $send->tracking_expires_at <= time()) {
            return $this->berakhir('expired');
        }

        if ($send->status === Send::STATUS_SELESAI) {
            return $this->berakhir('done');
        }

        if ($send->status === Send::STATUS_GAGAL) {
            return $this->berakhir('failed');
        }

        $pelanggan = $send->order?->customer;
        $tujuanLat = isset($pelanggan->latitude) ? (float) $pelanggan->latitude : null;
        $tujuanLng = isset($pelanggan->longitude) ? (float) $pelanggan->longitude : null;

        $posisi = null;
        $jarak = null;
        $status = 'on_the_way';

        $adaPosisi = $send->courier_position_at !== null
            && $send->courier_latitude !== null
            && $send->courier_longitude !== null;

        if ($adaPosisi) {
            $kurirLat = (float) $send->courier_latitude;
            $kurirLng = (float) $send->courier_longitude;

            // `updated_at` dikirim apa adanya, tidak disembunyikan walau sudah lama.
            // Halaman WAJIB menampilkannya: peta yang beku terlihat persis sama dengan
            // peta yang hidup, dan kurir yang masuk terowongan tidak boleh terlihat
            // seperti kurir yang berhenti bekerja.
            $posisi = [
                'latitude' => $kurirLat,
                'longitude' => $kurirLng,
                'accuracy_meters' => $send->courier_accuracy !== null
                    ? (float) $send->courier_accuracy
                    : null,
                'updated_at' => (int) $send->courier_position_at,
                'is_stale' => (time() - (int) $send->courier_position_at) > self::BASI_DETIK,
            ];

            if ($tujuanLat !== null && $tujuanLng !== null) {
                $jarak = Distance::meters($kurirLat, $kurirLng, $tujuanLat, $tujuanLng);

                if ($jarak <= Send::RADIUS_TIBA_METER) {
                    $status = 'arrived';
                }
            }
        }

        return response()->json([
            'status' => $status,
            'courier' => [
                // Nama depan saja. Pelanggan cukup tahu siapa yang datang; nama lengkap
                // karyawan di tautan yang bisa diteruskan tidak menambah apa pun.
                'name' => $this->namaDepan($send->user?->name),
                'photo' => Base64Image::url($send->user?->photo),
            ],
            'destination' => [
                'latitude' => $tujuanLat,
                'longitude' => $tujuanLng,
            ],
            'courier_position' => $posisi,
            'distance_meters' => $jarak,
            'expires_at' => $send->tracking_expires_at !== null
                ? (int) $send->tracking_expires_at
                : null,
        ]);
    }

    /**
     * Keadaan akhir. Sengaja seragam dan miskin isi: begitu tugasnya berakhir, tidak ada
     * lagi yang perlu diketahui pemegang tautan — termasuk siapa kurirnya.
     */
    private function berakhir(string $status): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'courier' => null,
            'destination' => null,
            'courier_position' => null,
            'distance_meters' => null,
            'expires_at' => null,
        ]);
    }

    private function namaDepan(?string $nama): ?string
    {
        if (empty($nama)) {
            return null;
        }

        return explode(' ', trim($nama))[0];
    }
}
