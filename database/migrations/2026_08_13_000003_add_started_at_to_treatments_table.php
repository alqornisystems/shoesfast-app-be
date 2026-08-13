<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jam teknisi benar-benar mulai mengerjakan.
 *
 * Kolom baru, BUKAN memakai ulang `date_start`: yang itu adalah jadwal rencana, dipakai
 * mengurutkan waiting list dan menghitung `progress`. Menimpanya saat teknisi menekan
 * "mulai" akan menggeser urutan antrean dan membuat progress melompat mundur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            if (! Schema::hasColumn('treatments', 'started_at')) {
                $table->integer('started_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('treatments', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
