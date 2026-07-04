<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BranchScoped;

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
}
