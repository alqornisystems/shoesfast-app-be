<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Guarantee extends Model
{
    use BranchScoped;

    protected $table = 'guarantees';

    protected $dateFormat = 'U';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id', 'orders_items_id', 'price', 'note', 'photo',
        'status', 'is_deleted', 'created_by', 'modified_by',
    ];

    protected $casts = [
        'orders_items_id' => 'integer',
        'price' => 'integer',
        'status' => 'integer',
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

    public function item()
    {
        return $this->belongsTo(OrderItem::class, 'orders_items_id');
    }
}
