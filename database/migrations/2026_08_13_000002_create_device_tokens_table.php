<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token perangkat FCM per pengguna. Satu pengguna boleh punya beberapa baris —
 * HP kantor dan HP pribadi, atau perangkat yang diganti.
 *
 * Token itu sendiri unik: FCM memindahkan token ke pengguna lain ketika sebuah
 * perangkat dipakai orang lain, dan tanpa kunci unik notifikasi tugas akan ikut
 * terkirim ke pemilik lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_tokens')) {
            return;
        }

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('users_id')->index();
            $table->string('token', 255)->unique();
            $table->string('platform', 20)->nullable();
            // Konvensi tabel lama: waktu unix integer, hapus lunak lewat flag.
            $table->integer('is_deleted')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('modified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
