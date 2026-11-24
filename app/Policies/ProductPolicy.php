<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-products')
                || $this->hasTenantPermission($user, 'manage-stock')
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, Product $product): bool
    {
        return $this->belongsToActiveTenant($user, $product) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-products');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->belongsToActiveTenant($user, $product)
            && $this->hasTenantPermission($user, 'manage-products');
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->belongsToActiveTenant($user, $product)
            && $this->hasTenantPermission($user, 'manage-products');
    }

    public function export(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-products')
            || $this->hasTenantPermission($user, 'view-reports');
    }

    public function import(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-products');
    }

    public function inspectStock(User $user, Product $product): bool
    {
        return $this->belongsToActiveTenant($user, $product)
            && ($this->hasTenantPermission($user, 'manage-products')
                || $this->hasTenantPermission($user, 'manage-stock'));
    }
}
