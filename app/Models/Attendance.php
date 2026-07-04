<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BranchScoped;

class Attendance extends Model
{
    use BranchScoped;

    protected $table = 'attendances';
    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'users_id',
        'clock',
        'type', // 0=clock_in, 1=clock_out
        'latitude',
        'longitude',
        'distance', // Distance in meters from branch location
        'is_wfa', // Work From Anywhere
        'is_deleted',
        'created_by',
    ];

    protected $casts = [
        'clock' => 'integer',
        'type' => 'integer',
        'is_wfa' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'created_by' => 'integer',
    ];

    const UPDATED_AT = null; // No updated_at for attendance

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
}
