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
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'invoice_token')) {
                $table->string('invoice_token', 40)->nullable()->unique()->after('note');
            }

            if (! Schema::hasColumn('orders', 'invoice_expires_at')) {
                $table->integer('invoice_expires_at')->nullable()->after('invoice_token');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'invoice_token')) {
                $table->dropUnique('orders_invoice_token_unique');
                $table->dropColumn('invoice_token');
            }

            if (Schema::hasColumn('orders', 'invoice_expires_at')) {
                $table->dropColumn('invoice_expires_at');
            }
        });
    }
};
