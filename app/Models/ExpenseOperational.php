<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BranchScoped;

class ExpenseOperational extends Model
{
    use BranchScoped;

    protected $table = 'expenses_oprationals';
    protected $dateFormat = 'U';

    protected $fillable = [
        'projects_id',
        'name',
        'note',
        'cost_basis',
        'nominal',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'nominal' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
    ];

    const UPDATED_AT = 'modified_at';

    protected static function boot()
    {
        parent::boot();
        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
