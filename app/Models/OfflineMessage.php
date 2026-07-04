<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfflineMessage extends Model
{
    protected $table = 'offline_messages';

    protected $fillable = [
        'phone',
        'date',
        'message',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public $timestamps = false;

    /**
     * Disable Laravel timestamps, use manual created_at if needed
     */
    protected $dateFormat = 'U';
}
