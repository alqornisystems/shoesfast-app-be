<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel legacy tidak punya migrasi dan produksi sudah terisi, jadi tiap kolom
// dijaga hasColumn supaya migrasi ini aman dijalankan berulang.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'pin')) {
                $table->string('pin', 255)->nullable();
            }
            if (! Schema::hasColumn('customers', 'pin_created_at')) {
                $table->integer('pin_created_at')->nullable();
            }
            if (! Schema::hasColumn('customers', 'pin_created_ip')) {
                $table->string('pin_created_ip', 45)->nullable();
            }
            if (! Schema::hasColumn('customers', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable();
            }
            if (! Schema::hasColumn('customers', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable();
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'pickup_address')) {
                $table->text('pickup_address')->nullable();
            }
            if (! Schema::hasColumn('orders', 'pickup_maps')) {
                $table->text('pickup_maps')->nullable();
            }
            if (! Schema::hasColumn('orders', 'source')) {
                $table->tinyInteger('source')->default(0);
            }
            if (! Schema::hasColumn('orders', 'points_awarded')) {
                $table->tinyInteger('points_awarded')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['pin', 'pin_created_at', 'pin_created_ip', 'latitude', 'longitude']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['pickup_address', 'pickup_maps', 'source', 'points_awarded']);
        });
    }
};
