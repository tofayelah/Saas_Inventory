<?php

namespace App\Modules\Crm\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Modules\Crm\Models\CrmLead;
use App\Policies\BaseTenantPolicy;

class CrmLeadPolicy extends BaseTenantPolicy
{
    /**
     * Pre-authorization checks.
     * Enforces that converted lead deletion is strictly forbidden for all actors,
     * including super admins and owners, to preserve canonical customer provenance.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($ability === 'delete') {
            return null; // Always evaluate delete() method to enforce converted lead invariant
        }

        return parent::before($user, $ability);
    }

    /**
     * Determine whether the user can view any leads.
     */
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user) ||
            $this->hasTenantPermission($user, 'crm.leads.view_all') ||
            $this->hasTenantPermission($user, 'crm.leads.view_branch') ||
            $this->hasTenantPermission($user, 'crm.leads.view_assigned');
    }

    /**
     * Determine whether the user can view the lead.
     */
    public function view(User $user, CrmLead $lead): bool
    {
        if (!$this->belongsToActiveTenant($user, $lead)) {
            return false;
        }

        // Owners and managers with tenant-wide view permission
        if ($this->isOwner($user) || $this->hasTenantPermission($user, 'crm.leads.view_all')) {
            return true;
        }

        // Resolve branch membership
        $membership = Membership::where('team_id', $user->current_team_id)
            ->where('user_id', $user->id)
            ->first();

        // If user is branch-constrained and lead has a warehouse, verify branch match
        if ($membership && $membership->warehouse_id && $lead->warehouse_id) {
            if ((int) $lead->warehouse_id !== (int) $membership->warehouse_id) {
                return false;
            }
        }

        // Branch-wide view permission within their branch
        if ($this->hasTenantPermission($user, 'crm.leads.view_branch')) {
            if ($membership && $membership->warehouse_id) {
                return (int) $lead->warehouse_id === (int) $membership->warehouse_id;
            }
        }

        // Check assigned-user access
        if ($this->hasTenantPermission($user, 'crm.leads.view_assigned')) {
            return (int) $lead->assigned_user_id === (int) $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create leads.
     */
    public function create(User $user): bool
    {
        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.leads.create');
    }

    /**
     * Determine whether the user can update the lead.
     */
    public function update(User $user, CrmLead $lead): bool
    {
        if (!$this->view($user, $lead)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.leads.edit');
    }

    /**
     * Determine whether the user can convert the lead.
     */
    public function convert(User $user, CrmLead $lead): bool
    {
        if (!$this->view($user, $lead)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.leads.convert');
    }

    /**
     * Determine whether the user can delete the lead.
     * Converted leads cannot be deleted under any circumstance.
     */
    public function delete(User $user, CrmLead $lead): bool
    {
        if (!$this->belongsToActiveTenant($user, $lead)) {
            return false;
        }

        // Hardening invariant: Converted leads can NEVER be deleted regardless of owner status or permissions
        if ($lead->status === 'converted' || $lead->converted_customer_id !== null) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.leads.delete');
    }
}
