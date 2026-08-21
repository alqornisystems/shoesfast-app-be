<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran bisa menunjuk BARANG, bukan cuma pesanan.
 *
 * Barang boleh dibawa pulang satu per satu setelah tagihannya sendiri lunas. Selama
 * payments hanya punya orders_id, "barang mana yang sudah dibayar" harus ditebak —
 * dan tebakan itu menahan barang orang yang sebenarnya sudah membayarnya.
 *
 * Nullable, dan memang akan tetap null untuk sebagian besar baris: 20.000-an
 * pembayaran lama tidak punya jawabannya, dan pembayaran yang melunasi seluruh
 * pesanan sekaligus memang tidak menunjuk barang mana pun. Keduanya jatuh ke
 * pembagian rata seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'orders_items_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->integer('orders_items_id')->nullable()->after('orders_id');
            $table->index('orders_items_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'orders_items_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['orders_items_id']);
            $table->dropColumn('orders_items_id');
        });
    }
};
