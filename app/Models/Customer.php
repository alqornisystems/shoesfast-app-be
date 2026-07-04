<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Customer extends Model
{
    protected $table = 'customers';

    protected $dateFormat = 'U';

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id',
        'name',
        'phone',
        'address',
        'email',
        'instagram',
        'photo',
        'maps',
        'date_of_birth',
        'hobby',
        'favorite_food',
        'behavior',
        'is_member',
        'member_code',
        'member_since',
        'points',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'date_of_birth' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
        'is_deleted' => 'integer',
        'is_member' => 'integer',
        'points' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
        });
    }

    /**
     * Override delete to perform soft delete
     */
    public function delete()
    {
        $this->is_deleted = 1;
        return $this->save();
    }

    /**
     * Force delete (actual deletion)
     */
    public function forceDelete()
    {
        return parent::delete();
    }

    /**
     * Customer bisa terdaftar di banyak cabang (many-to-many)
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'customer_project',
            'customers_id',
            'projects_id'
        );
    }

    /**
     * Relationship to Orders
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'customers_id');
    }
}
