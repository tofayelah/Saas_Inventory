<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;
use App\Modules\Crm\Models\CrmSupportCase;
use App\Policies\BaseTenantPolicy;

class CrmSupportCasePolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.cases.view_all');
    }

    public function view(User $user, CrmSupportCase $case): bool
    {
        return $this->belongsToActiveTenant($user, $case);
    }

    public function create(User $user): bool
    {
        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.cases.create');
    }

    public function update(User $user, CrmSupportCase $case): bool
    {
        if (!$this->belongsToActiveTenant($user, $case)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.cases.edit');
    }

    public function escalateRepair(User $user, CrmSupportCase $case): bool
    {
        if (!$this->belongsToActiveTenant($user, $case)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.cases.escalate_repair');
    }

    public function delete(User $user, CrmSupportCase $case): bool
    {
        if (!$this->belongsToActiveTenant($user, $case)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'crm.cases.delete');
    }
}
