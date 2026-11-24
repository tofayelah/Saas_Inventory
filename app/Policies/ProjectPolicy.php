<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-orders');
    }

    public function update(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function addItem(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function removeItem(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function addCost(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function removeCost(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function createBill(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function pay(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function transition(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function downloadPdf(User $user, Project $project): bool
    {
        return $this->belongsToActiveTenant($user, $project)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
