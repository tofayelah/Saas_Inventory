<?php

namespace App\Modules\Crm\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Modules\Crm\Models\CrmDeal;
use App\Policies\BaseTenantPolicy;

class CrmDealPolicy extends BaseTenantPolicy
{
    /**
     * Determine whether the user can view any deals.
     */
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user) ||
            $this->hasTenantPermission($user, 'crm.deals.view_all') ||
            $this->hasTenantPermission($user, 'crm.deals.view_assigned');
    }

    /**
     * Determine whether the user can view the deal.
     */
    public function view(User $user, CrmDeal $deal): bool
    {
        if (!$this->belongsToActiveTenant($user, $deal)) {
            return false;
        }

        // Owners and managers with tenant-wide view permission
        if ($this->isOwner($user) || $this->hasTenantPermission($user, 'crm.deals.view_all')) {
            return true;
        }

        // Resolve branch membership
        $membership = Membership::where('team_id', $user->current_team_id)
            ->where('user_id', $user->id)
            ->first();

        // If user is branch-constrained and deal has a warehouse, verify branch match
        if ($membership && $membership->warehouse_id && $deal->warehouse_id) {
            if ((int) $deal->warehouse_id !== (int) $membership->warehouse_id) {
                return false; // Sales rep cannot access deals outside assigned branch
            }
        }

        // Check assigned-user access
        if ($this->hasTenantPermission($user, 'crm.deals.view_assigned')) {
            return (int) $deal->assigned_user_id === (int) $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create deals.
     */
    public function create(User $user): bool
    {
        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.deals.create');
    }

    /**
     * Determine whether the user can update the deal.
     */
    public function update(User $user, CrmDeal $deal): bool
    {
        if (!$this->view($user, $deal)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.deals.edit');
    }

    /**
     * Determine whether the user can delete the deal.
     */
    public function delete(User $user, CrmDeal $deal): bool
    {
        if (!$this->belongsToActiveTenant($user, $deal)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.deals.delete');
    }
}
