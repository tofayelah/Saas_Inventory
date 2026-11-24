<?php

namespace App\Policies;

use App\Models\ChartOfAccount;
use App\Models\User;

class ChartOfAccountPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user) 
                || $this->hasTenantPermission($user, 'manage-accounting')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, ChartOfAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function update(User $user, ChartOfAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account)
            && $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function delete(User $user, ChartOfAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account)
            && $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function initialize(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function viewFinancialReports(User $user): bool
    {
        $team = $user->currentTeam;
        if (!$team || !$user->belongsToTeam($team)) {
            return false;
        }

        return $this->isOwner($user)
            || $this->hasTenantPermission($user, 'manage-accounting')
            || $this->hasTenantPermission($user, 'view-reports');
    }

    public function reconcile(User $user): bool
    {
        $team = $user->currentTeam;
        if (!$team || !$user->belongsToTeam($team)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'manage-accounting');
    }
}
