<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait, HasApiTokens;

    protected $table = 'customers';

    protected $dateFormat = 'U';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id',
        'name',
        'phone',
        'address',
        'email',
        'instagram',
        'photo',
        'maps',
        'date_of_birth',
        'hobby',
        'favorite_food',
        'behavior',
        'is_member',
        'member_code',
        'member_since',
        'points',
        'latitude',
        'longitude',
        'pin',
        'pin_created_at',
        'pin_created_ip',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    // PIN tidak pernah ikut dalam respons mana pun.
    protected $hidden = ['pin'];

    protected $casts = [
        'date_of_birth' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
        'is_deleted' => 'integer',
        'is_member' => 'integer',
        'points' => 'integer',
        'pin_created_at' => 'integer',
    ];

    /**
     * Kolom kata sandi tabel ini bernama `pin`, bukan `password`.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->pin;
    }

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', function ($query) {
            $query->where('is_deleted', 0);
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

    /**
     * Customer bisa terdaftar di banyak cabang (many-to-many)
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(
            Project::class,
            'customer_project',
            'customers_id',
            'projects_id'
        );
    }

    /**
     * Relationship to Orders
     */
    public function orders()
    {
        return $this->hasMany(Order::class, 'customers_id');
    }
}
