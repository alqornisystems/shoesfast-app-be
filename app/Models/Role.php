<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Role extends Model
{
    protected $table = 'roles';

    // Existing DB uses Unix integer timestamps
    protected $dateFormat = 'U';
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'name',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $hidden = [
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    // Global scope: exclude soft-deleted records
    protected static function booted(): void
    {
        static::addGlobalScope('active', function (Builder $builder) {
            $builder->where('is_deleted', 0);
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

    public function users()
    {
        return $this->hasMany(User::class, 'roles_id');
    }
}
