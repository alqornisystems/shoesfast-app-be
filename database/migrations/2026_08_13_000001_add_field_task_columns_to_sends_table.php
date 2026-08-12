<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom untuk alur tugas lapangan kurir: berangkat, gagal/jadwal ulang, dan bukti
 * serah terima. Sebelum ini satu-satunya akhir sebuah tugas adalah "selesai",
 * sehingga daftar tugas berjalan di kantor tidak pernah mencerminkan kenyataan.
 *
 * Tabel `sends` adalah tabel lama tanpa migration, jadi tiap kolom dijaga
 * hasColumn supaya migrasi ini aman dijalankan berulang di produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sends', function (Blueprint $table) {
            // B4 — status antara "sedang menuju lokasi". Disimpan sebagai cap waktu,
            // bukan kolom status baru: waktunya sendiri yang berguna untuk menjawab
            // "berangkat jam berapa", dan keberadaannya sudah menjadi penanda status.
            if (! Schema::hasColumn('sends', 'started_at')) {
                $table->integer('started_at')->nullable();
            }

            // B3 — kegagalan tugas. reason_code adalah daftar tertutup (lihat
            // SendController::REASON_CODES); teks bebas tidak bisa dilaporkan.
            if (! Schema::hasColumn('sends', 'failed_at')) {
                $table->integer('failed_at')->nullable();
            }
            if (! Schema::hasColumn('sends', 'reason_code')) {
                $table->string('reason_code', 30)->nullable();
            }
            if (! Schema::hasColumn('sends', 'fail_note')) {
                $table->text('fail_note')->nullable();
            }
            if (! Schema::hasColumn('sends', 'reschedule_date')) {
                $table->integer('reschedule_date')->nullable();
            }

            // B2 — bukti serah terima. Koordinat memakai presisi yang sama dengan
            // tabel lain di basis data ini.
            if (! Schema::hasColumn('sends', 'proof_photo')) {
                $table->text('proof_photo')->nullable();
            }
            if (! Schema::hasColumn('sends', 'receiver_name')) {
                $table->string('receiver_name', 100)->nullable();
            }
            if (! Schema::hasColumn('sends', 'proof_latitude')) {
                $table->decimal('proof_latitude', 10, 8)->nullable();
            }
            if (! Schema::hasColumn('sends', 'proof_longitude')) {
                $table->decimal('proof_longitude', 11, 8)->nullable();
            }
            if (! Schema::hasColumn('sends', 'proof_at')) {
                $table->integer('proof_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sends', function (Blueprint $table) {
            $table->dropColumn([
                'started_at', 'failed_at', 'reason_code', 'fail_note', 'reschedule_date',
                'proof_photo', 'receiver_name', 'proof_latitude', 'proof_longitude', 'proof_at',
            ]);
        });
    }
};
