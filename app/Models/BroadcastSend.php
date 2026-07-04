<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastSend extends Model
{
    use BranchScoped;

    protected $table = 'broadcasts_sends';
    protected $dateFormat = 'U'; // Unix timestamp

    const UPDATED_AT = 'modified_at';

    protected $fillable = [
        'projects_id',
        'broadcasts_templates_id',
        'users_id', // JSON array of user IDs or "all"
        'is_deleted',
        'created_by',
        'modified_by',
    ];

    protected $casts = [
        'is_deleted' => 'boolean',
        'created_at' => 'integer',
        'modified_at' => 'integer',
    ];

    protected $appends = [
        'recipients_count',
    ];

    /**
     * Relationship: Send belongs to a template
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(BroadcastTemplate::class, 'broadcasts_templates_id');
    }

    /**
     * Relationship: Send belongs to a branch
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    /**
     * Relationship: Created by user
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get recipient user IDs as array
     */
    public function getRecipientIds(): array
    {
        if ($this->users_id === 'all' || $this->users_id === null || $this->users_id === '') {
            return [];
        }

        // If already an array, return it
        if (is_array($this->users_id)) {
            return $this->users_id;
        }

        // Try to decode JSON
        $decoded = json_decode($this->users_id, true);

        // Ensure we return an array
        if (is_array($decoded)) {
            return $decoded;
        }

        // If it's a single ID, wrap it in an array
        if (is_numeric($this->users_id)) {
            return [(int) $this->users_id];
        }

        return [];
    }

    /**
     * Get recipients count
     */
    public function getRecipientsCountAttribute(): int
    {
        if ($this->users_id === 'all') {
            return User::where('projects_id', $this->projects_id)
                ->where('is_deleted', 0)
                ->count();
        }

        $ids = $this->getRecipientIds();
        return count($ids);
    }
}
