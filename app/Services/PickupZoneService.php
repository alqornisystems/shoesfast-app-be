<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Setting;

/**
 * Menentukan apakah alamat pelanggan masuk zona gratis jemput.
 *
 * Jarak dihitung ke titik CABANG PELANGGAN, bukan cabang terdekat: pelanggan
 * tetap dilayani cabangnya sendiri. Malang-Surabaya sekitar 80 km sehingga
 * radius 25 km tidak membuat kedua wilayah bertabrakan.
 */
class PickupZoneService
{
    private const DEFAULT_RADIUS_KM = 25.0;

    public function evaluate(Customer $customer): array
    {
        $radiusKm = (float) (Setting::where('key', 'free_pickup_radius_km')->value('value')
            ?: self::DEFAULT_RADIUS_KM);

        $branch = Project::find($customer->projects_id);

        // Tanpa koordinat, jarak tidak bisa dihitung. Jarak tidak pernah jadi
        // alasan menolak permintaan — yang menolak metode jemput adalah
        // ketiadaan titik peta, dan itu diputuskan di controller.
        if ($customer->latitude === null || $customer->longitude === null
            || $branch?->latitude === null || $branch?->longitude === null) {
            return [
                'eligible' => false,
                'reason' => 'tanpa_koordinat',
                'distance_km' => null,
                'radius_km' => $radiusKm,
            ];
        }

        $distanceKm = $this->haversineKm(
            (float) $customer->latitude,
            (float) $customer->longitude,
            (float) $branch->latitude,
            (float) $branch->longitude,
        );

        return [
            'eligible' => $distanceKm <= $radiusKm,
            'reason' => $distanceKm <= $radiusKm ? 'dalam_radius' : 'luar_radius',
            'distance_km' => round($distanceKm, 2),
            'radius_km' => $radiusKm,
        ];
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
