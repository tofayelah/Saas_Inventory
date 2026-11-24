<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;
use App\Modules\Crm\Models\CrmCampaign;
use App\Policies\BaseTenantPolicy;

class CrmCampaignPolicy extends BaseTenantPolicy
{
    /**
     * Determine whether the user can view any campaigns.
     */
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user) ||
            $this->hasTenantPermission($user, 'crm.campaigns.create') ||
            $this->hasTenantPermission($user, 'crm.campaigns.approve');
    }

    /**
     * Determine whether the user can view the campaign.
     */
    public function view(User $user, CrmCampaign $campaign): bool
    {
        return $this->belongsToActiveTenant($user, $campaign);
    }

    /**
     * Determine whether the user can create campaigns.
     */
    public function create(User $user): bool
    {
        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.campaigns.create');
    }

    /**
     * Determine whether the user can update the campaign.
     */
    public function update(User $user, CrmCampaign $campaign): bool
    {
        if (!$this->belongsToActiveTenant($user, $campaign)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.campaigns.edit');
    }

    /**
     * Determine whether the user can approve the campaign.
     * STRICT UNCONDITIONAL DUAL-CUSTODY: Creator cannot approve own campaign.
     */
    public function approve(User $user, CrmCampaign $campaign): bool
    {
        if (!$this->belongsToActiveTenant($user, $campaign)) {
            return false;
        }

        // Unconditional Dual-Custody Invariant:
        // No user—including the Tenant Owner—may approve their own campaign.
        if ((int) $campaign->created_by === (int) $user->id) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.campaigns.approve');
    }

    /**
     * Determine whether the user can dispatch the campaign.
     */
    public function dispatch(User $user, CrmCampaign $campaign): bool
    {
        if (!$this->belongsToActiveTenant($user, $campaign)) {
            return false;
        }

        // Must be in approved status to dispatch
        if (!$campaign->isApproved()) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.campaigns.dispatch');
    }

    /**
     * Determine whether the user can delete the campaign.
     */
    public function delete(User $user, CrmCampaign $campaign): bool
    {
        if (!$this->belongsToActiveTenant($user, $campaign)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.campaigns.delete');
    }
}
