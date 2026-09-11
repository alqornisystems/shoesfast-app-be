<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use BranchScoped;

    protected $table = 'orders_items';

    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'orders_id',
        'services_id',
        'photo',
        'name',
        'price',
        'discount',
        'status',
        'type',
        'checkbox',
        'note',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'price' => 'integer',
        'discount' => 'integer',
        'status' => 'integer',
        'type' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'created_by' => 'integer',
        'modified_by' => 'integer',
    ];

    const UPDATED_AT = 'modified_at';

    /**
     * Boot method to add global scope
     */
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            // Qualify the column so this scope stays unambiguous inside JOIN
            // queries (order/hpp/top-service reports join orders_items with
            // other tables that also have an is_deleted column).
            $query->where($query->getModel()->getTable().'.is_deleted', 0);
        });
    }

    /**
     * Relationship to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    /**
     * Relationship to Service
     */
    public function service()
    {
        return $this->belongsTo(Service::class, 'services_id');
    }

    /**
     * Relationship to Treatments
     */
    public function treatments()
    {
        return $this->hasMany(Treatment::class, 'orders_items_id');
    }

    /**
     * Relationship to Sends (Delivery)
     */
    public function sends()
    {
        return $this->hasMany(Send::class, 'orders_items_id');
    }

    /**
     * Barang yang siap dijadwalkan pulang — satu definisi untuk setiap daftar antar.
     *
     * Kesiapan dihitung dari PEKERJAANNYA, bukan dari kolom `status`. Kolom itu
     * basi di dua arah: ratusan barang yang selesai dikerjakan sebelum status barang
     * ikut naik masih tercatat 0 atau 1 dan tidak pernah muncul di antrean antar,
     * sementara barang yang terlanjur tercatat 2 bisa masih punya pekerjaan terbuka
     * dan dijadwalkan pulang setengah jadi.
     *
     * Syaratnya:
     * - pesanannya masih ada dan tidak dibatalkan — barang yatim tidak punya
     *   pelanggan maupun alamat untuk dituju;
     * - pesanannya belum berstatus Selesai (3) — admin menutup pesanan yang
     *   barangnya sudah diambil sendiri di toko, dan tidak ada kolom lain yang
     *   mencatat pengambilan itu;
     * - punya pekerjaan, dan SEMUA pekerjaannya sudah selesai (`done_at` terisi);
     * - belum punya tugas antar yang masih hidup — tugas yang dihapus tidak dihitung,
     *   jadi barangnya kembali ke antrean alih-alih hilang;
     * - belum sampai di tangan pelanggan (status 4).
     *
     * Pesanan, pekerjaan, dan tugas antarnya dinilai TANPA saringan cabang. Yang
     * dicari adalah milik barang ini, di cabang mana pun tercatat — data lama
     * menyimpan barang di satu cabang dan pekerjaannya di cabang lain, dan dari
     * kacamata cabang yang sedang aktif barang itu seolah tidak punya pekerjaan
     * sama sekali. Saringan cabang tetap berlaku pada barangnya sendiri.
     */
    public const PESANAN_SELESAI = 3;

    public function scopeSiapDiantar(Builder $query): Builder
    {
        return $query
            ->whereHas('order', function ($q) {
                $q->withoutGlobalScope('branch')->where('orders.status', '!=', self::PESANAN_SELESAI);
            })
            ->whereHas('treatments', function ($q) {
                $q->withoutGlobalScope('branch');
            })
            ->whereDoesntHave('treatments', function ($q) {
                $q->withoutGlobalScope('branch')->whereNull('done_at');
            })
            ->whereDoesntHave('sends', function ($q) {
                $q->withoutGlobalScope('branch')->where('type', 1);
            })
            ->where('orders_items.status', '!=', 4);
    }
}
