<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;

abstract class BaseTenantPolicy
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     * Platform Super Admin bypasses tenant policy checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Verify that the given model belongs strictly to the user's active tenant.
     */
    protected function belongsToActiveTenant(User $user, ?Model $model): bool
    {
        if ($model === null) {
            return false;
        }

        $activeTenantId = $user->current_team_id;

        if ($activeTenantId === null) {
            return false;
        }

        // Verify model's team_id matches active tenant
        if (isset($model->team_id) && (int) $model->team_id !== (int) $activeTenantId) {
            return false;
        }

        return true;
    }

    /**
     * Check if user is the owner of the active tenant.
     */
    protected function isOwner(User $user): bool
    {
        if ($user->currentTeam && $user->ownsTeam($user->currentTeam)) {
            return true;
        }

        return $user->hasRole('owner');
    }

    /**
     * Check if user has permission within active tenant context.
     */
    protected function hasTenantPermission(User $user, string $permission): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        try {
            return $user->can($permission) || $user->hasPermissionTo($permission);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
