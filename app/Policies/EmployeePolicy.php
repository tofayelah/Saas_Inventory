<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user) || $this->hasTenantPermission($user, 'manage-hr'));
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->belongsToActiveTenant($user, $employee) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-hr');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->belongsToActiveTenant($user, $employee)
            && $this->hasTenantPermission($user, 'manage-hr');
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $this->belongsToActiveTenant($user, $employee)
            && $this->hasTenantPermission($user, 'manage-hr');
    }
}
