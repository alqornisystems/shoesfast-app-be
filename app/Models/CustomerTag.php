<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerTag extends Model
{
    protected $table = 'customer_tags';

    protected $dateFormat = 'U';

    const UPDATED_AT = null;

    protected $fillable = ['customers_id', 'tag', 'created_by'];

    protected $casts = [
        'customers_id' => 'integer',
        'created_at' => 'integer',
    ];
}
