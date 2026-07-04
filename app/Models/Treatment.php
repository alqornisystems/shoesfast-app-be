<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BranchScoped;

class Treatment extends Model
{
    use BranchScoped;

    protected $table = 'treatments';
    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'users_id',
        'partnerships_id',
        'orders_items_id',
        'services_id',
        'status',
        'date_start',
        'date_end',
        'note',
        'price',
        'is_partnerships',
        'is_deleted',
        'done_at',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'status' => 'integer',
        'date_start' => 'integer',
        'date_end' => 'integer',
        'price' => 'integer',
        'is_partnerships' => 'integer',
        'is_deleted' => 'integer',
        'done_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'users_id' => 'integer',
        'partnerships_id' => 'integer',
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
            // queries (report queries join treatments with orders/orders_items/
            // services, which also have an is_deleted column).
            $query->where($query->getModel()->getTable().'.is_deleted', 0);
        });
    }

    /**
     * Relationship to OrderItem
     */
    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'orders_items_id');
    }

    /**
     * Relationship to Service
     */
    public function service()
    {
        return $this->belongsTo(Service::class, 'services_id');
    }

    /**
     * Relationship to User (karyawan yang handle)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * Relationship to Partnership (mitra/vendor yang handle)
     */
    public function partnership()
    {
        return $this->belongsTo(Partnership::class, 'partnerships_id');
    }

    /**
     * Relationship to Warranty (treatment has warranty)
     */
    public function warranty()
    {
        return $this->hasOne(Warranty::class, 'treatments_id');
    }
}
