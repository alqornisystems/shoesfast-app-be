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
        Schema::table('projects', function (Blueprint $table) {
            $table->text('full_address')->nullable();
            $table->string('whatsapp', 25)->nullable();
            $table->text('google_maps_url')->nullable();
            $table->string('instagram', 100)->nullable();
            $table->string('facebook', 100)->nullable();
            $table->string('tiktok', 100)->nullable();
            $table->string('website', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'full_address',
                'whatsapp',
                'google_maps_url',
                'instagram',
                'facebook',
                'tiktok',
                'website',
            ]);
        });
    }
};
