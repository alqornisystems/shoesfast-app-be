<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use BranchScoped;

    protected $table = 'holidays';

    protected $dateFormat = 'U'; // Unix timestamp

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'date',
        'name',
        'description',
        'projects_id',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'date' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
        });
    }

    // Relationships
    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }
}
