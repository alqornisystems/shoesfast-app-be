<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rekening tujuan pembayaran, per cabang.
 *
 * Ditaruh di sebelah `whatsapp` yang sudah ada di tabel yang sama, bukan di settings:
 * nomor WA-nya sudah per cabang, dan rekening yang global sementara nomor WA-nya per
 * cabang berarti pelanggan Surabaya diminta mentransfer ke rekening pusat lalu
 * mengirim buktinya ke nomor Surabaya. Keduanya harus tinggal di baris yang sama.
 */
return new class extends Migration
{
    private const KOLOM = [
        'bank_name' => 50,
        'bank_account_number' => 50,
        'bank_account_name' => 100,
    ];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            foreach (self::KOLOM as $nama => $panjang) {
                if (! Schema::hasColumn('projects', $nama)) {
                    $table->string($nama, $panjang)->nullable()->after('whatsapp');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            foreach (array_keys(self::KOLOM) as $nama) {
                if (Schema::hasColumn('projects', $nama)) {
                    $table->dropColumn($nama);
                }
            }
        });
    }
};
