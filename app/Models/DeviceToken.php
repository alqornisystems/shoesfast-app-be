<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token perangkat FCM milik seorang pengguna. Tanpa BranchScoped: token melekat
 * ke pengguna, bukan ke cabang — seorang admin yang berpindah cabang tetap harus
 * menerima notifikasinya di perangkat yang sama.
 */
class DeviceToken extends Model
{
    protected $table = 'device_tokens';

    protected $dateFormat = 'U'; // Unix timestamp

    protected $fillable = [
        'users_id',
        'token',
        'platform',
        'is_deleted',
    ];

    protected $casts = [
        'users_id' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
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
}
