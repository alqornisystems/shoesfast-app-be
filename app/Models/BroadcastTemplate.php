<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BroadcastTemplate extends Model
{
    use BranchScoped;

    protected $table = 'broadcasts_templates';
    protected $dateFormat = 'U'; // Unix timestamp

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id',
        'name',
        'content',
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
        'created_at' => 'integer',
        'modified_at' => 'integer',
    ];

    /**
     * Relationship: Template belongs to a branch
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    /**
     * Relationship: Template has many broadcast sends
     */
    public function broadcasts(): HasMany
    {
        return $this->hasMany(BroadcastSend::class, 'broadcasts_templates_id');
    }

    /**
     * Relationship: Created by user
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relationship: Modified by user
     */
    public function modifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    /**
     * Replace variables in template content
     * Variables: {customer_name}, {order_code}, {total}, {branch_name}, etc.
     */
    public function renderContent(array $data): string
    {
        $content = $this->content;

        foreach ($data as $key => $value) {
            $content = str_replace('{' . $key . '}', $value, $content);
        }

        return $content;
    }

    /**
     * Get available variables from content
     */
    public function getAvailableVariables(): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $this->content, $matches);
        return $matches[1] ?? [];
    }
}
