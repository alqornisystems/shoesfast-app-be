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
            $table->string('phone', 25)->nullable()->after('whatsapp');
            $table->string('email', 50)->nullable()->after('phone');
            $table->text('logo')->nullable()->after('email');
            $table->unsignedInteger('created_at')->nullable()->after('website');
            $table->unsignedInteger('modified_at')->nullable()->after('created_at');
            $table->unsignedInteger('created_by')->nullable()->after('modified_at');
            $table->unsignedInteger('modified_by')->nullable()->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'email',
                'logo',
                'created_at',
                'modified_at',
                'created_by',
                'modified_by',
            ]);
        });
    }
};
