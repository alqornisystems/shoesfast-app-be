<?php

namespace Tests\Feature\Customer;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel legacy tidak punya migrasi (produksi sumber kebenaran), jadi skema
 * sqlite :memory: dibangun manual di sini. Kolomnya cermin produksi plus
 * kolom baru dari migrasi portal pelanggan.
 */
trait CreatesCustomerSchema
{
    protected function createCustomerSchema(): void
    {
        $tables = [
            'projects', 'customers', 'orders', 'orders_items', 'services',
            'treatments', 'payments', 'sends', 'guarantees', 'rewards',
            'reward_redemptions', 'settings', 'personal_access_tokens',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('projects', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('whatsapp')->nullable();
            $t->decimal('latitude', 10, 8)->nullable();
            $t->decimal('longitude', 11, 8)->nullable();
            $t->text('maps')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('instagram')->nullable();
            $t->integer('date_of_birth')->nullable();
            $t->text('address')->nullable();
            $t->text('maps')->nullable();
            $t->text('photo')->nullable();
            $t->decimal('latitude', 10, 8)->nullable();
            $t->decimal('longitude', 11, 8)->nullable();
            $t->string('pin')->nullable();
            $t->integer('pin_created_at')->nullable();
            $t->string('pin_created_ip')->nullable();
            $t->tinyInteger('is_member')->default(0);
            $t->string('member_code')->nullable();
            // DATE, bukan integer. Skema test yang salah tipe di sini membuat
            // sqlite menerima unix timestamp yang ditolak MySQL produksi —
            // bugnya lolos seluruh test dan baru muncul di server.
            $t->date('member_since')->nullable();
            $t->integer('points')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('customers_id')->nullable();
            $t->string('code')->nullable();
            $t->integer('date')->nullable();
            $t->integer('total_discount')->default(0);
            $t->integer('total_price')->default(0);
            $t->text('note')->nullable();
            $t->string('invoice_token', 40)->nullable()->unique();
            $t->integer('invoice_expires_at')->nullable();
            $t->text('pickup_address')->nullable();
            $t->text('pickup_maps')->nullable();
            $t->tinyInteger('source')->default(0);
            $t->tinyInteger('points_awarded')->default(0);
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('orders_items', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('orders_id')->nullable();
            $t->integer('services_id')->nullable();
            $t->text('photo')->nullable();
            $t->string('name')->nullable();
            $t->integer('price')->default(0);
            $t->integer('discount')->default(0);
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('type')->default(0);
            $t->text('checkbox')->nullable();
            $t->text('note')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('services', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('price')->default(0);
            $t->integer('hpp')->nullable();
            $t->integer('estimation')->nullable();
            $t->text('photo')->nullable();
            $t->text('description')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('treatments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('users_id')->nullable();
            $t->integer('partnerships_id')->nullable();
            $t->integer('orders_items_id')->nullable();
            $t->integer('services_id')->nullable();
            $t->tinyInteger('status')->default(0);
            $t->integer('date_start')->nullable();
            $t->integer('date_end')->nullable();
            $t->integer('done_at')->nullable();
            $t->text('note')->nullable();
            $t->integer('price')->default(0);
            $t->tinyInteger('is_partnerships')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('orders_id')->nullable();
            $t->integer('date')->nullable();
            $t->integer('nominal')->default(0);
            $t->text('note')->nullable();
            $t->text('photo')->nullable();
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('sends', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('users_id')->nullable();
            $t->integer('orders_id')->nullable();
            $t->integer('orders_items_id')->nullable();
            $t->integer('date')->nullable();
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('type')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('guarantees', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('orders_items_id');
            $t->integer('price')->nullable();
            $t->text('note')->nullable();
            $t->text('photo')->nullable();
            $t->tinyInteger('status')->default(0);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('rewards', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->string('name');
            $t->tinyInteger('type')->default(0);
            $t->integer('services_id')->nullable();
            $t->integer('points_cost');
            $t->text('photo')->nullable();
            $t->tinyInteger('is_active')->default(1);
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('reward_redemptions', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('customers_id');
            $t->integer('rewards_id');
            $t->string('code', 20)->unique();
            $t->integer('points_spent');
            $t->tinyInteger('status')->default(0);
            $t->integer('date');
            $t->tinyInteger('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('modified_by')->nullable();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });
    }
}
