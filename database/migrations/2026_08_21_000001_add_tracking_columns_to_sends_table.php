<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesi pelacakan kurir, terikat SATU tugas.
 *
 * Kenapa di baris `sends` dan bukan tabel jejak tersendiri: yang disimpan hanya posisi
 * TERAKHIR. Riwayat jejak satu jam adalah peta kebiasaan seseorang, dan tidak ada satu pun
 * pertanyaan pelanggan yang membutuhkannya — yang ditanya cuma "kurirnya di mana sekarang".
 *
 * Token acak, bukan turunan dari id: id yang bisa ditebak berarti tautan yang bisa ditebak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sends', function (Blueprint $table) {
            if (! Schema::hasColumn('sends', 'tracking_token')) {
                $table->string('tracking_token', 64)->nullable()->unique();
            }
            if (! Schema::hasColumn('sends', 'tracking_expires_at')) {
                $table->integer('tracking_expires_at')->nullable();
            }
            if (! Schema::hasColumn('sends', 'courier_latitude')) {
                $table->decimal('courier_latitude', 10, 8)->nullable();
            }
            if (! Schema::hasColumn('sends', 'courier_longitude')) {
                $table->decimal('courier_longitude', 11, 8)->nullable();
            }
            // Tanpa akurasi, halaman pelanggan tidak bisa membedakan "kurir di depan pintu"
            // dari "sinyal GPS meleset 300 m", dan menggambar keduanya sama yakinnya.
            if (! Schema::hasColumn('sends', 'courier_accuracy')) {
                $table->float('courier_accuracy')->nullable();
            }
            if (! Schema::hasColumn('sends', 'courier_position_at')) {
                $table->integer('courier_position_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sends', function (Blueprint $table) {
            $table->dropColumn([
                'tracking_token', 'tracking_expires_at', 'courier_latitude',
                'courier_longitude', 'courier_accuracy', 'courier_position_at',
            ]);
        });
    }
};
