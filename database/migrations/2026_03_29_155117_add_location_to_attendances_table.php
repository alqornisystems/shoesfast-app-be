<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('type');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->decimal('distance', 8, 2)->nullable()->after('longitude')->comment('Distance in meters from branch location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'distance']);
        });
    }
};
