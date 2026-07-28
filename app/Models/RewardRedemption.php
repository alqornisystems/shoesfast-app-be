<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class RewardRedemption extends Model
{
    use BranchScoped;

    protected $table = 'reward_redemptions';

    protected $dateFormat = 'U';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id', 'customers_id', 'rewards_id', 'code', 'points_spent',
        'status', 'date', 'is_deleted', 'created_by', 'modified_by',
    ];

    protected $casts = [
        'customers_id' => 'integer',
        'rewards_id' => 'integer',
        'points_spent' => 'integer',
        'status' => 'integer',
        'date' => 'integer',
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

    public function reward()
    {
        return $this->belongsTo(Reward::class, 'rewards_id');
    }
}
