<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * BranchScoped Trait
 *
 * Automatically filters queries by projects_id based on active branch context.
 *
 * Usage:
 * - Add this trait to any model that has projects_id column
 * - Super admin (projects_id = null) sees all data
 * - Branch users only see data from their branch
 */
trait BranchScoped
{
    /**
     * Boot the trait and add global scope
     */
    protected static function bootBranchScoped(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            $activeBranch = app('branch.context')->getActiveBranch();

            // Super admin (null) sees everything
            if ($activeBranch === null) {
                return;
            }

            // Branch users only see their branch data
            $builder->where($builder->getModel()->getTable() . '.projects_id', $activeBranch);
        });

        // Auto-assign projects_id when creating new records
        static::creating(function ($model) {
            if (!isset($model->projects_id)) {
                $activeBranch = app('branch.context')->getActiveBranch();

                // Only auto-assign if user has a branch (not super admin)
                if ($activeBranch !== null) {
                    $model->projects_id = $activeBranch;
                }
            }
        });
    }

    /**
     * Query without branch scope (for super admin operations)
     */
    public function scopeWithoutBranchScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('branch');
    }

    /**
     * Query specific branch (for super admin switching)
     */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query->withoutGlobalScope('branch')
            ->when($branchId !== null, fn($q) => $q->where('projects_id', $branchId));
    }
}
