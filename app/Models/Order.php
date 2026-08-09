<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use BranchScoped;

    protected $table = 'orders';

    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'customers_id',
        'code',
        'date',
        'total_discount',
        'total_price',
        'note',
        'status',
        'invoice_token',
        'invoice_expires_at',
        'pickup_address',
        'pickup_maps',
        'source',
        'points_awarded',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'date' => 'integer',
        'total_discount' => 'integer',
        'total_price' => 'integer',
        'status' => 'integer',
        'invoice_expires_at' => 'integer',
        'source' => 'integer',
        'points_awarded' => 'integer',
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
            $query->where('is_deleted', 0);
        });
    }

    /**
     * Kode invoice berikutnya. Format: INV{YYYYMM}{0001}, urut per bulan.
     *
     * Tinggal di model, bukan di OrderController, karena pesanan sekarang lahir dari dua
     * tempat: layar admin dan jemput ulang kurir (SendController::reorder). Dua generator
     * berarti dua invoice bisa memakai nomor yang sama.
     *
     * Pencarian nomor terakhir sengaja MELEWATI branch scope: kalau tidak, cabang B yang
     * belum punya pesanan bulan ini akan mulai lagi dari 0001 dan menabrak kode cabang A.
     */
    public static function generateCode(): string
    {
        $prefix = 'INV';
        $yearMonth = date('Ym');

        $lastOrder = static::withoutGlobalScope('branch')
            ->where('code', 'LIKE', "{$prefix}{$yearMonth}%")
            ->orderBy('code', 'DESC')
            ->first();

        $newNumber = $lastOrder ? ((int) substr($lastOrder->code, -4)) + 1 : 1;

        return $prefix.$yearMonth.str_pad((string) $newNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Relationship to Customer
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customers_id');
    }

    /**
     * Relationship to OrderItems
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'orders_id');
    }

    /**
     * Relationship to Project/Branch
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    /**
     * Relationship to Sends (Pickup & Delivery)
     */
    public function sends()
    {
        return $this->hasMany(Send::class, 'orders_id');
    }

    /**
     * Relationship to Payments
     */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'orders_id');
    }
}
