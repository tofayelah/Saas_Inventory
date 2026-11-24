<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-stock')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-stock');
    }

    public function update(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function markOrdered(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function receive(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function cancel(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function close(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function pay(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function delete(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function downloadPdf(User $user, PurchaseOrder $order): bool
    {
        return $this->belongsToActiveTenant($user, $order)
            && ($this->hasTenantPermission($user, 'manage-stock')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
