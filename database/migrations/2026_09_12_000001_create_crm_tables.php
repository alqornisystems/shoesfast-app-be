<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan dan tag pelanggan untuk CRM.
 *
 * Catatan berupa riwayat — siapa menulis apa, kapan — bukan satu kolom yang
 * ditimpa. Kolom `customers.behavior` yang sudah ada tetap dipakai sebagai
 * ringkasan singkat.
 *
 * Tag tidak punya tabel master: daftar tag adalah tag yang sedang dipakai.
 * Kunci unik (pelanggan, tag) mencegah satu tag menempel dua kali.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_notes')) {
            Schema::create('customer_notes', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('customers_id')->index();
                $table->text('note');
                // Konvensi tabel lama: waktu unix integer, hapus lunak lewat flag.
                $table->integer('is_deleted')->default(0);
                $table->integer('created_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->integer('modified_at')->nullable();
                $table->integer('modified_by')->nullable();
            });
        }

        if (! Schema::hasTable('customer_tags')) {
            Schema::create('customer_tags', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('customers_id');
                $table->string('tag', 50)->index();
                $table->integer('created_at')->nullable();
                $table->integer('created_by')->nullable();
                $table->unique(['customers_id', 'tag']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customer_notes');
    }
};
