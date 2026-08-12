<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema sqlite untuk jalur tugas lapangan kurir (sends, orders, payments, device_tokens).
 *
 * Tabel-tabel ini legacy dan tidak punya migration — produksi adalah sumber kebenaran —
 * jadi test membangunnya sendiri. TIPE kolom harus sama persis dengan produksi: skema test
 * yang menyimpang pernah meloloskan bug member_since (kolom DATE ditulis unix integer)
 * melewati seluruh suite sampai meledak di server.
 */
trait CreatesFieldTaskSchema
{
    protected function createFieldTaskSchema(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->integer('is_deleted')->default(0);
        });

        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->text('photo')->nullable();
            $t->integer('modified_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            $t->integer('roles_id')->nullable();
            $t->integer('projects_id')->nullable();
            $t->integer('is_deleted')->default(0);
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->text('address')->nullable();
            $t->text('maps')->nullable();
            $t->integer('is_member')->default(0);
            $t->integer('points')->default(0);
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('customers_id')->nullable();
            $t->string('code')->nullable();
            $t->integer('date')->nullable();
            $t->integer('status')->default(0);
            // Boleh NULL: pesanan portal pelanggan lahir tanpa harga, dan petugas
            // menentukannya setelah barang diperiksa.
            $t->integer('total_price')->nullable();
            $t->integer('points_awarded')->default(0);
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('orders_items', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('orders_id')->nullable();
            $t->string('name')->nullable();
            $t->integer('type')->default(0);
            $t->text('photo')->nullable();
            $t->text('note')->nullable();
            $t->text('checkbox')->nullable();
            $t->integer('status')->default(0);
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('orders_id')->nullable();
            $t->integer('date')->nullable();
            $t->integer('nominal')->default(0);
            $t->text('note')->nullable();
            $t->text('photo')->nullable();
            $t->integer('is_deleted')->default(0);
            $t->integer('created_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });

        Schema::create('sends', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('projects_id')->default(1);
            $t->integer('users_id')->nullable();
            $t->integer('orders_id')->nullable();
            $t->integer('orders_items_id')->nullable();
            $t->integer('date')->nullable();
            $t->integer('status')->default(0);
            $t->integer('type')->default(0);
            $t->integer('is_deleted')->default(0);
            $t->integer('created_by')->nullable();
            $t->integer('modified_by')->nullable();
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
            // Kolom alur tugas lapangan — cermin migrasi
            // 2026_08_13_000001_add_field_task_columns_to_sends_table.
            $t->integer('started_at')->nullable();
            $t->integer('failed_at')->nullable();
            $t->string('reason_code', 30)->nullable();
            $t->text('fail_note')->nullable();
            $t->integer('reschedule_date')->nullable();
            $t->text('proof_photo')->nullable();
            $t->string('receiver_name', 100)->nullable();
            $t->decimal('proof_latitude', 10, 8)->nullable();
            $t->decimal('proof_longitude', 11, 8)->nullable();
            $t->integer('proof_at')->nullable();
        });

        Schema::create('device_tokens', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('users_id');
            $t->string('token', 255)->unique();
            $t->string('platform', 20)->nullable();
            $t->integer('is_deleted')->default(0);
            $t->integer('created_at')->nullable();
            $t->integer('modified_at')->nullable();
        });
    }
}
