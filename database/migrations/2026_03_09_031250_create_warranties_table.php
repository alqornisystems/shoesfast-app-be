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
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('projects_id')->nullable(); // Multi-branch support
            $table->unsignedBigInteger('orders_items_id'); // Item yang di-garansi
            $table->integer('price')->default(0)->comment('Biaya garansi');
            $table->tinyInteger('status')->default(0)->comment('0=Submission, 1=Admin Check, 2=Approve');
            $table->text('note')->nullable()->comment('Alasan garansi');
            $table->string('photo')->nullable()->comment('Bukti foto garansi');
            $table->tinyInteger('is_deleted')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->integer('created_at');
            $table->unsignedBigInteger('modified_by')->nullable();
            $table->integer('modified_at')->nullable();

            // Indexes
            $table->index('projects_id');
            $table->index('orders_items_id');
            $table->index('status');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
