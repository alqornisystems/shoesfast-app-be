<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan pekerjaan oleh teknisi.
 *
 * Teknisi boleh mengambil pekerjaan dari waiting list, tapi tidak langsung menjadi miliknya:
 * pengajuannya menunggu persetujuan admin. Dua kolom ini menyimpan pengajuan itu terpisah dari
 * `users_id`, supaya `users_id` tetap berarti satu hal saja — "pekerjaan ini sudah resmi
 * dipegang siapa". Selama menunggu, pekerjaan tetap terhitung belum berpemilik.
 *
 * claim_status: null/0 = tidak ada pengajuan, 1 = menunggu, 2 = disetujui, 3 = ditolak.
 * Idempoten terhadap DB produksi yang sudah termigrasi (lihat CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('treatments')) {
            return;
        }

        Schema::table('treatments', function (Blueprint $table) {
            if (! Schema::hasColumn('treatments', 'claim_users_id')) {
                $table->integer('claim_users_id')->nullable()->after('users_id');
            }
            if (! Schema::hasColumn('treatments', 'claim_status')) {
                $table->tinyInteger('claim_status')->nullable()->default(0)->after('claim_users_id');
            }
            if (! Schema::hasColumn('treatments', 'claim_at')) {
                $table->integer('claim_at')->nullable()->after('claim_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('treatments')) {
            return;
        }

        Schema::table('treatments', function (Blueprint $table) {
            foreach (['claim_at', 'claim_status', 'claim_users_id'] as $column) {
                if (Schema::hasColumn('treatments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
