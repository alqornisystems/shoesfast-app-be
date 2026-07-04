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
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->integer('date'); // Unix timestamp
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('projects_id')->nullable(); // Null = all branches
            $table->tinyInteger('is_deleted')->default(0);
            $table->integer('created_at'); // Unix timestamp
            $table->integer('modified_at')->nullable(); // Unix timestamp
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('modified_by')->nullable();

            $table->index('date');
            $table->index('projects_id');
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
