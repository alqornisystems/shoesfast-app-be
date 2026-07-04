<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BranchScoped;

class DailyNote extends Model
{
    use BranchScoped;

    protected $table = 'issues';

    protected $dateFormat = 'U'; // Unix timestamp

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id',
        'users_id',
        'title',
        'description',
        'date',
        'status',
        'repeated',
        'is_repeated',
        'is_deleted',
        'created_by',
        'modified_by',
        'done_at',
        'done_by',
    ];

    protected $casts = [
        'date' => 'integer',
        'status' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
        'done_at' => 'integer',
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
        });
    }

    /**
     * Relationship to User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    /**
     * Relationship to Project (Branch)
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    /**
     * Scope: Only active (not deleted)
     */
    public function scopeActive($query)
    {
        return $query->where('is_deleted', 0);
    }
}
