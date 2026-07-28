<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Nilai awal yang bisa diubah pemilik toko lewat admin panel tanpa deploy.
 * firstOrCreate, bukan updateOrCreate: menjalankan ulang seeder tidak boleh
 * mengembalikan nilai yang sudah disesuaikan di produksi.
 */
class PortalSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Rp 25.000 = 1 poin. Pesanan rata-rata Rp 350.000 menghasilkan 14
            // poin; imbal balik sekitar 4%.
            'points_rupiah_per_point' => '25000',

            // Malang-Surabaya sekitar 80 km, jadi 25 km tidak membuat kedua
            // wilayah bertabrakan.
            'free_pickup_radius_km' => '25',

            'free_pickup_terms' => 'Gratis jemput untuk domisili Malang kota dan sekitarnya, '
                .'serta Surabaya kota dan sekitarnya. Di luar wilayah tersebut, '
                .'ongkos kirim ditanggung pelanggan.',

            'default_branch_id' => '1',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
