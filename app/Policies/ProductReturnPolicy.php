<?php

namespace App\Policies;

use App\Models\ProductReturn;
use App\Models\User;

class ProductReturnPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, ProductReturn $return): bool
    {
        return $this->belongsToActiveTenant($user, $return) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-orders');
    }

    public function approve(User $user, ProductReturn $return): bool
    {
        return $this->belongsToActiveTenant($user, $return)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'manage-stock'));
    }

    public function reject(User $user, ProductReturn $return): bool
    {
        return $this->belongsToActiveTenant($user, $return)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'manage-stock'));
    }

    public function complete(User $user, ProductReturn $return): bool
    {
        return $this->belongsToActiveTenant($user, $return)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'manage-stock'));
    }

    public function downloadCreditNote(User $user, ProductReturn $return): bool
    {
        return $this->belongsToActiveTenant($user, $return)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
