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
