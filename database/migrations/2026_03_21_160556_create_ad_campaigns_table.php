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
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('platform'); // 'google' or 'meta'
            $table->string('campaign_name');
            $table->string('campaign_id')->nullable();
            $table->integer('date'); // Unix timestamp
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->decimal('cost', 15, 2)->default(0); // in IDR
            $table->integer('conversions')->default(0); // orders generated
            $table->decimal('conversion_value', 15, 2)->default(0); // revenue from conversions
            $table->decimal('ctr', 10, 2)->default(0); // Click-through rate (%)
            $table->decimal('cpc', 15, 2)->default(0); // Cost per click
            $table->decimal('cpa', 15, 2)->default(0); // Cost per acquisition
            $table->decimal('roas', 10, 2)->default(0); // Return on ad spend
            $table->text('notes')->nullable();
            $table->integer('projects_id')->nullable(); // branch
            $table->integer('users_id')->nullable(); // created by
            $table->tinyInteger('is_deleted')->default(0);
            $table->timestamps();

            $table->index(['platform', 'date']);
            $table->index('is_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
