<?php

namespace App\Jobs\Middleware;

use App\Contracts\TenantAwareJob;
use App\Exceptions\TenantContextMissingException;
use App\Services\TenantContext;

class TenantAwareJobMiddleware
{
    /**
     * Process the queued job within the restored tenant context.
     */
    public function handle(object $job, callable $next): mixed
    {
        $tenantId = null;

        if ($job instanceof TenantAwareJob) {
            $tenantId = $job->getTenantId();
        } elseif (isset($job->teamId) && is_numeric($job->teamId)) {
            $tenantId = (int) $job->teamId;
        } elseif (isset($job->team_id) && is_numeric($job->team_id)) {
            $tenantId = (int) $job->team_id;
        }

        if (!$tenantId) {
            throw new TenantContextMissingException(
                "Queued job [" . get_class($job) . "] failed: No tenant identifier provided for background execution."
            );
        }

        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        return $tenantContext->executeAsTenant($tenantId, function () use ($next, $job) {
            return $next($job);
        });
    }
}
