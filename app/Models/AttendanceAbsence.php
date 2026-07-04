<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BranchScoped;

class AttendanceAbsence extends Model
{
    use BranchScoped;

    protected $table = 'attendances_absences';
    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'projects_id',
        'users_id',
        'note',
        'date_start',
        'date_end',
        'total_days',
        'photo',
        'type', // 0=sakit, 1=izin, 2=cuti
        'is_approval', // 0=pending, 1=approved, 2=rejected
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'date_start' => 'integer',
        'date_end' => 'integer',
        'total_days' => 'integer',
        'type' => 'integer',
        'is_approval' => 'integer',
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
