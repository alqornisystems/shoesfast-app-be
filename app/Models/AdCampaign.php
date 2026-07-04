<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCampaign extends Model
{
    protected $table = 'ad_campaigns';

    protected $fillable = [
        'platform',
        'campaign_name',
        'campaign_id',
        'date',
        'impressions',
        'clicks',
        'cost',
        'conversions',
        'conversion_value',
        'ctr',
        'cpc',
        'cpa',
        'roas',
        'notes',
        'projects_id',
        'users_id',
        'is_deleted',
    ];

    protected $casts = [
        'date' => 'integer',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'cost' => 'decimal:2',
        'conversions' => 'integer',
        'conversion_value' => 'decimal:2',
        'ctr' => 'decimal:2',
        'cpc' => 'decimal:2',
        'cpa' => 'decimal:2',
        'roas' => 'decimal:2',
        'is_deleted' => 'integer',
    ];

    protected $dateFormat = 'U'; // Unix timestamp

    /**
     * Relationship: Campaign belongs to a project (branch)
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'projects_id');
    }

    /**
     * Relationship: Campaign created by user
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }
}
