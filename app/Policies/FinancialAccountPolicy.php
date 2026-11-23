<?php

namespace App\Policies;

use App\Models\FinancialAccount;
use App\Models\User;

class FinancialAccountPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user) || $this->hasTenantPermission($user, 'manage-accounting'));
    }

    public function view(User $user, FinancialAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function update(User $user, FinancialAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account)
            && $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function delete(User $user, FinancialAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account)
            && $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function toggleActive(User $user, FinancialAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account)
            && $this->hasTenantPermission($user, 'manage-accounting');
    }

    public function setDefault(User $user, FinancialAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account)
            && $this->hasTenantPermission($user, 'manage-accounting');
    }
}
