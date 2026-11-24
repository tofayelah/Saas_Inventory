<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-accounting')
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'manage-stock')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->belongsToActiveTenant($user, $expense) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-accounting')
            || $this->hasTenantPermission($user, 'manage-orders')
            || $this->hasTenantPermission($user, 'manage-stock');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->belongsToActiveTenant($user, $expense)
            && ($this->hasTenantPermission($user, 'manage-accounting')
                || $this->hasTenantPermission($user, 'manage-team'));
    }
}
