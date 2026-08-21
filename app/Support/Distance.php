<?php

namespace App\Support;

class Distance
{
    /**
     * Jarak garis lurus dua titik dalam meter (haversine).
     *
     * Garis lurus, bukan jarak tempuh: menampilkannya sebagai "2,4 km lagi" jujur, sedangkan
     * menerjemahkannya jadi menit akan salah setiap kali ada satu perlintasan kereta.
     */
    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000; // jari-jari bumi, meter

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
