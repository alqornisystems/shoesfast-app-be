<?php

namespace App\Services;

use App\Models\UserPreference;
use Illuminate\Support\Facades\Auth;

/**
 * BranchContext Service
 *
 * Manages the active branch context for the current request.
 * Handles branch switching for super admin and enforces branch isolation.
 */
class BranchContext
{
    /**
     * Get the active branch ID for current request
     *
     * Logic:
     * 1. If user has projects_id (branch user) -> return user's branch
     * 2. If user is super admin (projects_id = null):
     *    a) Check database preference for switched branch -> return that
     *    b) No switched branch -> return null (see all)
     */
    public function getActiveBranch(): ?int
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        // Branch users always locked to their branch
        if ($user->projects_id !== null) {
            return $user->projects_id;
        }

        // Super admin can switch branches via database preference
        // If no branch selected in preference, return null (see all data)
        $preference = UserPreference::where('users_id', $user->id)->first();

        return $preference?->active_branch_id;
    }

    /**
     * Check if current user is super admin
     */
    public function isSuperAdmin(): bool
    {
        $user = Auth::user();

        return $user && $user->projects_id === null;
    }

    /**
     * Switch active branch (super admin only)
     *
     * @param int|null $branchId - null means "view all branches"
     * @return bool Success status
     */
    public function switchBranch(?int $branchId): bool
    {
        if (!$this->isSuperAdmin()) {
            return false;
        }

        $user = Auth::user();

        // Update or create user preference
        UserPreference::updateOrCreate(
            ['users_id' => $user->id],
            ['active_branch_id' => $branchId]
        );

        return true;
    }

    /**
     * Get current active branch name
     */
    public function getActiveBranchName(): string
    {
        $branchId = $this->getActiveBranch();

        if ($branchId === null) {
            return $this->isSuperAdmin() ? 'Semua Cabang' : 'Tidak Ada';
        }

        $project = \App\Models\Project::find($branchId);

        return $project?->name ?? 'Unknown Branch';
    }

    /**
     * Reset branch context (logout)
     */
    public function reset(): void
    {
        // No need to reset anything - preferences persist across sessions
        // This is now a no-op but kept for backwards compatibility
    }
}
