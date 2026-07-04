<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Service extends Model
{
    protected $table = 'services';

    protected $fillable = [
        'name',
        'price',
        'hpp',
        'estimation',
        'photo',
        'description',
        'is_deleted',
    ];

    // Unix timestamp format
    protected $dateFormat = 'U';

    // Cast attributes
    protected $casts = [
        'price' => 'integer',
        'hpp' => 'integer',
        'estimation' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
    ];

    // Use modified_at instead of updated_at
    const UPDATED_AT = 'modified_at';

    /**
     * Global scope to exclude soft deleted records
     */
    protected static function booted(): void
    {
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('is_deleted', 0);
        });
    }

    /**
     * Override delete to perform soft delete
     */
    public function delete()
    {
        // Set is_deleted to 1 instead of actual deletion
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
     * Scope to include deleted records
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('not_deleted');
    }

    /**
     * Service has many HPP items
     */
    public function hppItems()
    {
        return $this->hasMany(ServiceHpp::class, 'services_id');
    }

    /**
     * Get HPP items by status
     */
    public function hppByStatus(string $status)
    {
        return $this->hasMany(ServiceHpp::class, 'services_id')->where('status', $status);
    }

    /**
     * Get direct materials
     */
    public function directMaterials()
    {
        return $this->hppByStatus('direct');
    }

    /**
     * Get direct labor
     */
    public function directLabor()
    {
        return $this->hppByStatus('labor');
    }

    /**
     * Get indirect materials
     */
    public function indirectMaterials()
    {
        return $this->hppByStatus('indirect');
    }
}
