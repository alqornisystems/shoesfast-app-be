<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BranchScoped;

class Payment extends Model
{
    use BranchScoped;

    protected $table = 'payments';
    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'orders_id',
        'date',
        'nominal',
        'note',
        'photo',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'date' => 'integer',
        'nominal' => 'integer',
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
     * Relationship to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    /**
     * Relationship to Project/Branch
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }
}
