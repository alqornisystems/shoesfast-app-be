<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    // Existing DB uses Unix integer timestamps
    protected $dateFormat = 'U';

    public $timestamps = true;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'photo',
        'roles_id',
        'projects_id',
        'payment_date',
        'account_number',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $hidden = [
        'password',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    // Disable auto password hashing — we handle it manually (bcrypt + sha1 legacy)
    protected function casts(): array
    {
        return [];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('active', function (Builder $builder) {
            $builder->where('is_deleted', 0);
        });
    }

    /**
     * Override delete to perform soft delete
     */
    public function delete()
    {
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

    public function role()
    {
        return $this->belongsTo(Role::class, 'roles_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    public function payrolls()
    {
        return $this->hasMany(UserPayroll::class, 'users_id');
    }
}
