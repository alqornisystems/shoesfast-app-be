<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'users_id',
        'active_branch_id',
    ];

    // Use Unix timestamp format (integer)
    protected $dateFormat = 'U';

    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'modified_at';

    /**
     * Get the user that owns the preference.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }
}
