<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BranchScoped;

class Send extends Model
{
    use BranchScoped;

    protected $table = 'sends';
    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'users_id',
        'orders_id',
        'orders_items_id',
        'date',
        'status',
        'type',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'date' => 'integer',
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
            $query->where('is_deleted', 0);
        });
    }

    /**
     * Relationship to User (kurir)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * Relationship to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    /**
     * Relationship to OrderItem
     */
    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'orders_items_id');
    }

    /**
     * Relationship to Project/Branch
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }
}
