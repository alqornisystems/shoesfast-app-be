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
        Schema::create('customer_project', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('customers_id');
            $table->unsignedInteger('projects_id');
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();

            $table->index(['customers_id', 'projects_id']);
            $table->unique(['customers_id', 'projects_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_project');
    }
};
