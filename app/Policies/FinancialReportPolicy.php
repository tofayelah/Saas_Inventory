<?php

namespace App\Policies;

use App\Models\ChartOfAccount;
use App\Models\User;

class FinancialReportPolicy extends BaseTenantPolicy
{
    /**
     * Determine whether the user can view financial report statements (Trial Balance, P&L, Balance Sheet, GL).
     */
    public function viewReports(User $user): bool
    {
        $team = $user->currentTeam;
        if (!$team || !$user->belongsToTeam($team)) {
            return false;
        }

        return $this->isOwner($user)
            || $this->hasTenantPermission($user, 'manage-accounting')
            || $this->hasTenantPermission($user, 'view-reports');
    }

    /**
     * Determine whether the user can export or download financial report statements as PDF/Excel.
     */
    public function exportReports(User $user): bool
    {
        return $this->viewReports($user);
    }

    /**
     * Determine whether the user can access or execute bank reconciliation workflows.
     */
    public function reconcile(User $user): bool
    {
        $team = $user->currentTeam;
        if (!$team || !$user->belongsToTeam($team)) {
            return false;
        }

        return $this->isOwner($user) || $this->hasTenantPermission($user, 'manage-accounting');
    }

    /**
     * Determine whether the user can view drilldown ledger details for a specific chart of account.
     */
    public function viewAccountLedger(User $user, ChartOfAccount $account): bool
    {
        return $this->belongsToActiveTenant($user, $account) && $this->viewReports($user);
    }
}
