<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    use BranchScoped;

    protected $table = 'rewards';

    protected $dateFormat = 'U';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id', 'name', 'type', 'services_id', 'points_cost',
        'photo', 'is_active', 'is_deleted', 'created_by', 'modified_by',
    ];

    protected $casts = [
        'type' => 'integer',
        'services_id' => 'integer',
        'points_cost' => 'integer',
        'is_active' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', fn ($query) => $query->where('is_deleted', 0));
    }

    public function delete()
    {
        $this->is_deleted = 1;

        return $this->save();
    }

    public function service()
    {
        return $this->belongsTo(Service::class, 'services_id');
    }
}
