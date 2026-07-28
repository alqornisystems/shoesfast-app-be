<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rewards')) {
            Schema::create('rewards', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('projects_id')->default(1);
                $table->string('name', 100);
                $table->tinyInteger('type')->default(0); // 0=layanan, 1=barang
                $table->integer('services_id')->nullable();
                $table->integer('points_cost');
                $table->text('photo')->nullable();
                $table->tinyInteger('is_active')->default(1);
                $table->tinyInteger('is_deleted')->default(0);
                $table->integer('created_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->integer('modified_at')->nullable();
                $table->integer('modified_by')->nullable();
            });
        }

        if (! Schema::hasTable('reward_redemptions')) {
            Schema::create('reward_redemptions', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('projects_id')->default(1);
                $table->integer('customers_id')->index();
                $table->integer('rewards_id');
                $table->string('code', 20)->unique();
                $table->integer('points_spent');
                $table->tinyInteger('status')->default(0); // 0=menunggu diambil, 1=selesai
                $table->integer('date');
                $table->tinyInteger('is_deleted')->default(0);
                $table->integer('created_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->integer('modified_at')->nullable();
                $table->integer('modified_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
        Schema::dropIfExists('rewards');
    }
};
