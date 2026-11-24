<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;

class PayrollPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user) || $this->hasTenantPermission($user, 'manage-hr'));
    }

    public function view(User $user, Payroll $payroll): bool
    {
        return $this->belongsToActiveTenant($user, $payroll) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-hr');
    }

    public function generate(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-hr');
    }

    public function pay(User $user, Payroll $payroll): bool
    {
        return $this->belongsToActiveTenant($user, $payroll)
            && $this->hasTenantPermission($user, 'manage-hr');
    }

    public function delete(User $user, Payroll $payroll): bool
    {
        return $this->belongsToActiveTenant($user, $payroll)
            && $this->hasTenantPermission($user, 'manage-hr');
    }

    public function downloadPdf(User $user, Payroll $payroll): bool
    {
        return $this->belongsToActiveTenant($user, $payroll)
            && ($this->hasTenantPermission($user, 'manage-hr')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
