<?php

namespace App\Policies;

use App\Models\ServiceTicket;
use App\Models\User;

class ServiceTicketPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user)
                || $this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function view(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-orders');
    }

    public function update(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function addPart(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function removePart(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function transition(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function pay(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function delete(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && $this->hasTenantPermission($user, 'manage-orders');
    }

    public function downloadToken(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }

    public function downloadInvoice(User $user, ServiceTicket $ticket): bool
    {
        return $this->belongsToActiveTenant($user, $ticket)
            && ($this->hasTenantPermission($user, 'manage-orders')
                || $this->hasTenantPermission($user, 'view-reports'));
    }
}
