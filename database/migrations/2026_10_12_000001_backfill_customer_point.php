<?php

use App\Models\Customer;
use App\Support\MapsPoint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memanen koordinat yang selama ini terkubur di dalam URL `customers.maps`.
 *
 * Di produksi 3.913 dari 3.916 URL memuat `@lintang,bujur`, sementara kolom
 * `latitude`/`longitude` hanya terisi pada 1 dari 4.664 baris — tidak ada satu
 * pun jalur tulis yang pernah mengisinya. Data titiknya sudah dimiliki
 * Shoesfast; yang belum ada hanya pemindahannya ke bentuk yang bisa dipakai.
 *
 * Hanya menyentuh baris yang koordinatnya NULL, jadi aman dijalankan berulang
 * dan tidak pernah menimpa titik yang sudah benar. Baris terhapus lunak ikut
 * dipanen: pelanggan bisa dipulihkan, dan titik yang hilang saat pemulihan
 * adalah kejutan yang tidak perlu.
 *
 * Memakai PHP, bukan regex SQL, supaya aturan penerimaan nilainya sama persis
 * dengan jalur tulis biasa — termasuk penolakan di luar rentang dan penolakan
 * 0,0 (lihat App\Support\MapsPoint).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers')
            || ! Schema::hasColumn('customers', 'latitude')
            || ! Schema::hasColumn('customers', 'maps')) {
            return;
        }

        Customer::withoutGlobalScope('notDeleted')
            ->whereNull('latitude')
            ->whereNotNull('maps')
            ->where('maps', '<>', '')
            ->select(['id', 'maps'])
            ->chunkById(500, function ($baris) {
                foreach ($baris as $pelanggan) {
                    $titik = MapsPoint::fromUrl($pelanggan->maps);

                    if ($titik === null) {
                        continue;
                    }

                    // DB::table, bukan $pelanggan->save(): ini menulis kolom
                    // turunan secara langsung dan tidak boleh menyentuh
                    // `modified_at`/`modified_by`. Backfill bukan suntingan
                    // manusia, dan riwayat tidak boleh berbohong soal itu.
                    DB::table('customers')
                        ->where('id', $pelanggan->id)
                        ->update([
                            'latitude' => $titik['latitude'],
                            'longitude' => $titik['longitude'],
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Tidak dibalikkan. Koordinat hasil panen tidak bisa dibedakan dari
        // yang ditaruh manusia sesudahnya, jadi mengosongkannya kembali berisiko
        // membuang titik yang benar. `maps` tetap utuh, panen bisa diulang.
    }
};
