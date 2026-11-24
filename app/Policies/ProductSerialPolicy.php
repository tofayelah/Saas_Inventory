<?php

namespace App\Policies;

use App\Models\ProductSerial;
use App\Models\User;

class ProductSerialPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'manage-stock')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, ProductSerial $serial): bool
    {
        return $this->belongsToActiveTenant($user, $serial) && $this->viewAny($user);
    }

    public function markDefective(User $user, ProductSerial $serial): bool
    {
        return $this->belongsToActiveTenant($user, $serial)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'manage-stock'));
    }
}
