<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;

class BrandPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam);
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->belongsToActiveTenant($user, $brand) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-products');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->belongsToActiveTenant($user, $brand)
            && $this->hasTenantPermission($user, 'manage-products');
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->belongsToActiveTenant($user, $brand)
            && $this->hasTenantPermission($user, 'manage-products');
    }
}
