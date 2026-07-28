<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Poin diberikan saat pesanan LUNAS DIBAYAR, bukan saat status pesanan
 * berubah. Alasannya bukan selera: admin panel memberi label status 3 =
 * "Dibatalkan" padahal 1.473 dari 2.448 baris berstatus 3 lunas dibayar.
 * Memakai kelunasan melewati keruwetan itu dan tetap benar kalau arti status
 * berubah lagi.
 */
class CustomerPointService
{
    private const DEFAULT_RUPIAH_PER_POINT = 25000;

    public function rupiahPerPoint(): int
    {
        $value = (int) Setting::where('key', 'points_rupiah_per_point')->value('value');

        return $value > 0 ? $value : self::DEFAULT_RUPIAH_PER_POINT;
    }

    /**
     * @return int poin yang baru saja diberikan; 0 bila tidak memenuhi syarat
     */
    public function awardIfSettled(int $ordersId): int
    {
        return DB::transaction(function () use ($ordersId) {
            // lockForUpdate supaya dua pembayaran yang tersimpan bersamaan
            // tidak sama-sama lolos pemeriksaan points_awarded.
            $order = Order::withoutGlobalScope('branch')
                ->where('id', $ordersId)
                ->lockForUpdate()
                ->first();

            if (! $order || (int) $order->points_awarded === 1) {
                return 0;
            }

            if ((int) $order->total_price <= 0) {
                return 0;
            }

            $totalPaid = (int) Payment::withoutGlobalScope('branch')
                ->where('orders_id', $order->id)
                ->sum('nominal');

            if ($totalPaid < (int) $order->total_price) {
                return 0;
            }

            $customer = Customer::withoutGlobalScope('branch')
                ->where('id', $order->customers_id)
                ->lockForUpdate()
                ->first();

            // Keanggotaan diperiksa saat pesanan menjadi lunas, bukan saat
            // pesanan dibuat. Bukan member tidak mengumpulkan apa pun, dan
            // menandai points_awarded di sini yang membuat bergabung
            // belakangan tidak memberi poin surut.
            if (! $customer || (int) $customer->is_member !== 1) {
                $order->update(['points_awarded' => 1]);

                return 0;
            }

            $points = intdiv((int) $order->total_price, $this->rupiahPerPoint());

            if ($points < 1) {
                $order->update(['points_awarded' => 1]);

                return 0;
            }

            $customer->update(['points' => (int) $customer->points + $points]);
            $order->update(['points_awarded' => 1]);

            return $points;
        });
    }
}
