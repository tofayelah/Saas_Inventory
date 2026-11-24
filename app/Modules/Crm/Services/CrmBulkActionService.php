<?php

namespace App\Modules\Crm\Services;

use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantIsolationException;
use App\Modules\Crm\Models\CrmDeal;
use App\Modules\Crm\Models\CrmLead;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;

class CrmBulkActionService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Bulk update deals within strict tenant-scoped action boundary.
     * Prevents QueryBuilder un-scoped update bypass by validating tenant context and enforcing WHERE team_id = ?.
     */
    public function bulkUpdateDeals(int $teamId, array $dealIds, array $attributes): int
    {
        $activeTenantId = $this->tenantContext->getTenantId();

        if ($activeTenantId === null && !$this->tenantContext->isPrivileged()) {
            throw new TenantContextMissingException("Cannot perform bulk operations without an active tenant context.");
        }

        if ($activeTenantId !== null && $activeTenantId !== $teamId) {
            throw new TenantIsolationException("Cross-tenant bulk operation blocked: requested tenant [{$teamId}] does not match active context [{$activeTenantId}].");
        }

        // Strip any malicious attempt to reassign team_id
        unset($attributes['team_id'], $attributes['id']);

        if (empty($dealIds)) {
            return 0;
        }

        // Strictly enforce tenant boundary in raw DB query
        return DB::table('crm_deals')
            ->where('team_id', $teamId)
            ->whereIn('id', $dealIds)
            ->update(array_merge($attributes, ['updated_at' => now()]));
    }

    /**
     * Bulk update leads within strict tenant-scoped action boundary.
     */
    public function bulkUpdateLeads(int $teamId, array $leadIds, array $attributes): int
    {
        $activeTenantId = $this->tenantContext->getTenantId();

        if ($activeTenantId === null && !$this->tenantContext->isPrivileged()) {
            throw new TenantContextMissingException("Cannot perform bulk operations without an active tenant context.");
        }

        if ($activeTenantId !== null && $activeTenantId !== $teamId) {
            throw new TenantIsolationException("Cross-tenant bulk operation blocked: requested tenant [{$teamId}] does not match active context [{$activeTenantId}].");
        }

        unset($attributes['team_id'], $attributes['id']);

        if (empty($leadIds)) {
            return 0;
        }

        return DB::table('crm_leads')
            ->where('team_id', $teamId)
            ->whereIn('id', $leadIds)
            ->update(array_merge($attributes, ['updated_at' => now()]));
    }
}
