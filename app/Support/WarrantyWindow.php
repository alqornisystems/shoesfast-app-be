<?php

namespace App\Support;

use App\Models\Guarantee;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Send;

/**
 * Masa klaim garansi: kapan sebuah barang masih boleh diklaim.
 *
 * Aturannya ditulis SEKALI di sini karena dipakai dua tempat yang harus tidak
 * pernah berbeda jawaban: endpoint klaim yang menolak, dan detail pesanan yang
 * memutuskan tombolnya muncul atau tidak. Tombol yang muncul lalu ditolak saat
 * ditekan lebih buruk daripada tombol yang tidak pernah muncul.
 */
class WarrantyWindow
{
    /** Sepekan sejak barang diterima. */
    public const DAYS = 7;

    /** Pesanan yang sudah ditutup — barangnya sudah berpindah ke pelanggan. */
    private const ORDER_DONE = 3;

    /**
     * Kapan barang ini sampai di tangan pelanggan. null kalau belum.
     *
     * Berjenjang, memakai yang paling meyakinkan lebih dulu:
     *   1. pengiriman selesai untuk barang itu sendiri
     *   2. pengiriman selesai untuk pesanannya (baris lama tanpa orders_items_id)
     *   3. saat pesanannya ditutup — untuk barang yang diambil sendiri di cabang,
     *      dan tidak meninggalkan baris pengiriman apa pun
     *
     * Tanggal selesai DIKERJAKAN sengaja tidak dipakai sama sekali. Sepatu yang
     * menginap seminggu di rak toko sudah kehabisan garansi sebelum pemiliknya
     * memegangnya, dan yang dijanjikan garansi adalah hasil kerja yang dipakai —
     * bukan hasil kerja yang masih di rak.
     */
    public static function receivedAt(Order $order, OrderItem $item): ?int
    {
        $perBarang = Send::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)
            ->where('type', 1)->where('status', 1)
            ->max('date');

        if ($perBarang) {
            return (int) $perBarang;
        }

        $perPesanan = Send::withoutGlobalScope('branch')
            ->where('orders_id', $order->id)
            ->whereNull('orders_items_id')
            ->where('type', 1)->where('status', 1)
            ->max('date');

        if ($perPesanan) {
            return (int) $perPesanan;
        }

        // Diambil sendiri di cabang. Tidak ada baris pengiriman, jadi satu-satunya
        // jejak serah terima adalah saat pesanannya ditutup.
        if ((int) $order->status === self::ORDER_DONE) {
            return self::unix($order->modified_at);
        }

        return null;
    }

    /**
     * modified_at adalah kolom UPDATED_AT, jadi Eloquent menyerahkannya sebagai Carbon
     * meski di database ia unix detik biasa. Kolom lain di skema ini di-cast ke integer
     * dan langsung bisa dipakai — yang satu ini tidak, dan menjumlahkannya dengan detik
     * melempar TypeError, bukan menghasilkan angka yang salah.
     */
    private static function unix(mixed $nilai): ?int
    {
        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->getTimestamp();
        }

        return $nilai ? (int) $nilai : null;
    }

    /**
     * Keadaan klaim barang ini, siap dikirim apa adanya ke portal.
     *
     * @return array{eligible: bool, until: int|null, reason: string|null}
     */
    public static function status(Order $order, OrderItem $item): array
    {
        // Klaim yang masih ditinjau menutup pintu untuk klaim kedua atas barang yang
        // sama — dua klaim kembar cuma membuat dua orang mengerjakan satu masalah.
        $sedangDitinjau = Guarantee::withoutGlobalScope('branch')
            ->where('orders_items_id', $item->id)
            ->where('status', 0)
            ->exists();

        if ($sedangDitinjau) {
            return ['eligible' => false, 'until' => null, 'reason' => 'sedang_ditinjau'];
        }

        if ((int) $order->status !== self::ORDER_DONE) {
            return ['eligible' => false, 'until' => null, 'reason' => 'belum_selesai'];
        }

        $diterima = self::receivedAt($order, $item);

        if ($diterima === null) {
            return ['eligible' => false, 'until' => null, 'reason' => 'belum_diterima'];
        }

        $batas = $diterima + (self::DAYS * 86400);

        return [
            'eligible' => time() <= $batas,
            'until' => $batas,
            'reason' => time() <= $batas ? null : 'lewat',
        ];
    }
}
