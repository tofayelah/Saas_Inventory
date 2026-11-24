<?php

namespace App\Policies;

use App\Models\SalesOrder;
use App\Models\User;

class SalesOrderPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-orders');
    }

    public function update(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function confirm(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function dispatchOrder(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function complete(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function cancel(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function pay(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function delete(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function downloadPdf(User $user, SalesOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
