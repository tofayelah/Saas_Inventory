<?php

namespace App\Policies;

use App\Models\DeliveryChallan;
use App\Models\User;

class DeliveryChallanPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, DeliveryChallan $challan): bool
    {
        return $this->belongsToActiveTenant($user, $challan) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-orders');
    }

    public function update(User $user, DeliveryChallan $challan): bool
    {
        return $this->belongsToActiveTenant($user, $challan)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function deliver(User $user, DeliveryChallan $challan): bool
    {
        return $this->belongsToActiveTenant($user, $challan)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function delete(User $user, DeliveryChallan $challan): bool
    {
        return $this->belongsToActiveTenant($user, $challan)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function downloadPdf(User $user, DeliveryChallan $challan): bool
    {
        return $this->belongsToActiveTenant($user, $challan)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
