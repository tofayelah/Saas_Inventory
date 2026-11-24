<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam);
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToActiveTenant($user, $warehouse) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-warehouses');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToActiveTenant($user, $warehouse)
            && $this->hasTenantPermission($user, 'manage-warehouses');
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToActiveTenant($user, $warehouse)
            && $this->hasTenantPermission($user, 'manage-warehouses');
    }

    public function setDefault(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToActiveTenant($user, $warehouse)
            && $this->hasTenantPermission($user, 'manage-warehouses');
    }

    public function inspect(User $user, Warehouse $warehouse): bool
    {
        return $this->belongsToActiveTenant($user, $warehouse)
            && ($this->hasTenantPermission($user, 'manage-warehouses')
                || $this->hasTenantPermission($user, 'manage-stock'));
    }
}
