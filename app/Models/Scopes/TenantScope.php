<?php

namespace App\Models\Scopes;

use App\Exceptions\TenantContextMissingException;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TenantScope implements \Illuminate\Database\Eloquent\Scope
{
    /**
     * Apply the tenant scope to a given Eloquent query builder.
     *
     * Strict Fail-Closed Rule:
     * 1. If an active tenant context exists, scope strictly by that tenant's ID.
     * 2. If the operation is explicitly running in privileged mode, allow un-scoped execution.
     * 3. In console/testing, if a query explicitly defines a team_id constraint, allow it.
     * 4. Otherwise, REJECT the operation by throwing TenantContextMissingException or applying an impossible constraint.
     */
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        // 1. Resolve tenant ID from TenantContext or fallback to authenticated user's current team
        $tenantId = $tenantContext->getTenantId();

        if (Auth::check() && Auth::user()->current_team_id) {
            $userTeamId = (int) Auth::user()->current_team_id;
            // Only synchronize in console/testing if not currently executing inside an explicit executeAsTenant callback
            if (!$tenantContext->isHttpRequest() && !$tenantContext->isExplicit() && $tenantId !== $userTeamId) {
                $tenantContext->setTenant($userTeamId);
                $tenantId = $userTeamId;
            } elseif (!$tenantId) {
                $tenantContext->setTenant($userTeamId);
                $tenantId = $userTeamId;
            }
        }

        // 2. If tenant ID is resolved, apply strict tenant constraint
        if ($tenantId) {
            $builder->where($model->qualifyColumn('team_id'), $tenantId);
            return;
        }

        // 3. If explicit privileged mode is active (platform super-admin / system maintenance)
        if ($tenantContext->isPrivileged()) {
            return;
        }

        // 4. Fail-Closed: Reject un-scoped queries on tenant-owned models
        if (app()->runningInConsole()) {
            // Check if the query builder already contains an explicit team_id clause in console/testing setup
            $hasExplicitTeamWhere = false;
            foreach ($builder->getQuery()->wheres as $where) {
                $column = $where['column'] ?? '';
                if ($column === 'team_id' || $column === $model->qualifyColumn('team_id') || str_ends_with($column, '.team_id')) {
                    $hasExplicitTeamWhere = true;
                    break;
                }
            }

            if ($hasExplicitTeamWhere) {
                return;
            }

            // Un-scoped query in console/queue: fail closed and throw exception
            $builder->whereRaw('1 = 0');
            throw new TenantContextMissingException(
                "Tenant-scoped query on model [" . get_class($model) . "] executed without an active or authorized tenant context."
            );
        }

        // In web/API requests without tenant context, ensure zero data is returned
        $builder->whereRaw('1 = 0');
    }
}
