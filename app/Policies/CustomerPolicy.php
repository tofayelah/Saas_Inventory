<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'manage-stock')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->belongsToActiveTenant($user, $customer) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-orders');
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->belongsToActiveTenant($user, $customer)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->belongsToActiveTenant($user, $customer)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function payDue(User $user, Customer $customer): bool
    {
        return $this->belongsToActiveTenant($user, $customer)
            && $this->hasTenantPermission($user, 'manage-orders');
    }
}
