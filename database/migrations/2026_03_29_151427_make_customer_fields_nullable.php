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
        Schema::table('customers', function (Blueprint $table) {
            // Make address and maps nullable - only name and phone are required
            $table->text('address')->nullable()->change();
            $table->text('maps')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Revert to NOT NULL
            $table->text('address')->nullable(false)->change();
            $table->text('maps')->nullable(false)->change();
        });
    }
};
