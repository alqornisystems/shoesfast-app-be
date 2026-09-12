<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerNote extends Model
{
    protected $table = 'customer_notes';

    protected $dateFormat = 'U';

    const UPDATED_AT = 'modified_at';

    protected $fillable = ['customers_id', 'note', 'is_deleted', 'created_by', 'modified_by'];

    protected $casts = [
        'customers_id' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'integer',
        'modified_at' => 'integer',
        'created_by' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::addGlobalScope('notDeleted', fn ($query) => $query->where('customer_notes.is_deleted', 0));
    }
}
