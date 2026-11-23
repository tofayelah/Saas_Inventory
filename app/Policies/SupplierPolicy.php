<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

class SupplierPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-stock')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->belongsToActiveTenant($user, $supplier) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-stock');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->belongsToActiveTenant($user, $supplier)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->belongsToActiveTenant($user, $supplier)
            && $this->hasTenantPermission($user, 'manage-stock');
    }

    public function toggleActive(User $user, Supplier $supplier): bool
    {
        return $this->belongsToActiveTenant($user, $supplier)
            && $this->hasTenantPermission($user, 'manage-stock');
    }
}
