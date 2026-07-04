<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily notes disimpan di tabel `issues` (lihat App\Models\DailyNote::$table).
     * Kolom `status` sudah ada di skema produksi, jadi penambahan diguard agar idempotent.
     */
    public function up(): void
    {
        if (Schema::hasTable('issues') && ! Schema::hasColumn('issues', 'status')) {
            Schema::table('issues', function (Blueprint $table) {
                $table->tinyInteger('status')->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('issues') && Schema::hasColumn('issues', 'status')) {
            Schema::table('issues', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
