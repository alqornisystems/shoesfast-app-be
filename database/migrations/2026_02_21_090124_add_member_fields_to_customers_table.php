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
            $table->tinyInteger('is_member')->default(0)->after('behavior');
            $table->string('member_code', 50)->nullable()->after('is_member');
            $table->date('member_since')->nullable()->after('member_code');
            $table->integer('points')->default(0)->after('member_since');

            $table->index('is_member');
            $table->index('member_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['is_member']);
            $table->dropIndex(['member_code']);
            $table->dropColumn(['is_member', 'member_code', 'member_since', 'points']);
        });
    }
};
