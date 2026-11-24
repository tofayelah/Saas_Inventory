<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->belongsToTeam($user->currentTeam)
            && ($this->isOwner($user) || $this->hasTenantPermission($user, 'manage-accounting'));
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $this->belongsToActiveTenant($user, $entry) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasTenantPermission($user, 'manage-accounting');
    }
}
