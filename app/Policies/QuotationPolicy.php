<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $this->belongsToActiveTenant($user, $quotation) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-orders');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $this->belongsToActiveTenant($user, $quotation)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function convert(User $user, Quotation $quotation): bool
    {
        return $this->belongsToActiveTenant($user, $quotation)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return $this->belongsToActiveTenant($user, $quotation)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function downloadPdf(User $user, Quotation $quotation): bool
    {
        return $this->belongsToActiveTenant($user, $quotation)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
