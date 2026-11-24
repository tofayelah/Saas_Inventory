<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;

class ProductCategoryPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam);
    }

    public function view(User $user, ProductCategory $category): bool
    {
        return $this->belongsToActiveTenant($user, $category) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-products');
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $this->belongsToActiveTenant($user, $category)
            && $this->hasTenantPermission($user, 'manage-products');
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return $this->belongsToActiveTenant($user, $category)
            && $this->hasTenantPermission($user, 'manage-products');
    }
}
