<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServiceHpp extends Model
{
    use BranchScoped;

    protected $table = 'services_hpp';

    // Existing DB uses Unix integer timestamps
    protected $dateFormat = 'U';
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id',
        'services_id',
        'name',
        'unit',
        'total_stock',
        'usage_per_service',
        'total_cost',
        'cost_per_usage',
        'status',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'total_stock' => 'integer',
        'usage_per_service' => 'integer',
        'total_cost' => 'integer',
        'cost_per_usage' => 'integer',
    ];

    /**
     * Relationship to Service
     */
    public function service()
    {
        return $this->belongsTo(Service::class, 'services_id');
    }

    /**
     * Scope by service ID
     */
    public function scopeForService(Builder $query, int $serviceId): Builder
    {
        return $query->where('services_id', $serviceId);
    }

    /**
     * Scope by status (direct, indirect, labor)
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
