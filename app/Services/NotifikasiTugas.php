<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;

/**
 * Pembungkus tipis di atas FcmService untuk notifikasi tugas per pengguna:
 * ambil token perangkat yang aktif, lalu kirim satu per satu.
 *
 * Gerbang FCM_ENABLED sudah ditangani FcmService, jadi tidak ada gerbang kedua
 * di sini.
 */
class NotifikasiTugas
{
    public function __construct(private FcmService $fcm) {}

    /**
     * Kirim ke semua perangkat aktif milik seorang pengguna.
     *
     * @param  array  $data  Payload data; wajib memuat tujuan dalam aplikasi
     *                       ({"route": "...", "id": ...}).
     */
    public function keUser(int $usersId, string $judul, string $isi, array $data = []): void
    {
        $tokens = DeviceToken::where('users_id', $usersId)->pluck('token');

        foreach ($tokens as $token) {
            // Kegagalan kirim tidak boleh menggagalkan aksi yang memicunya:
            // seorang admin tidak boleh gagal menyetujui izin hanya karena FCM
            // sedang bermasalah. Catat, lalu lanjut ke perangkat berikutnya.
            try {
                $this->fcm->sendToDevice($token, $judul, $isi, $data);
            } catch (\Throwable $e) {
                Log::warning('Notifikasi tugas gagal dikirim', [
                    'users_id' => $usersId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Tugas baru (pickup/delivery) ditugaskan ke seorang kurir.
     */
    public function tugasBaru(int $usersId, int $sendsId): void
    {
        $this->keUser(
            $usersId,
            'Tugas Baru',
            'Kamu mendapat tugas baru. Segera dicek ya!',
            ['route' => 'send', 'id' => (string) $sendsId],
        );
    }

    /**
     * Pengajuan izin sudah diputuskan admin.
     */
    public function izinDiputuskan(int $usersId, int $absencesId, bool $disetujui): void
    {
        $this->keUser(
            $usersId,
            $disetujui ? 'Izin Disetujui' : 'Izin Ditolak',
            $disetujui
                ? 'Pengajuan izinmu telah disetujui.'
                : 'Pengajuan izinmu ditolak.',
            ['route' => 'absence', 'id' => (string) $absencesId],
        );
    }
}
