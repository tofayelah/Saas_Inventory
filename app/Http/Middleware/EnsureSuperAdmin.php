<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function __construct(protected TenantContext $tenantContext) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isSuperAdmin()) {
            abort(403, 'Unauthorized. Platform Super-Administrator privileges required.');
        }

        // When navigating anywhere inside the /admin control plane, ensure the super admin is in platform mode
        if ($user->current_team_id !== null) {
            $user->forceFill(['current_team_id' => null])->save();
        }

        // Enable privileged mode for platform control plane requests
        return $this->tenantContext->executePrivileged(function () use ($next, $request) {
            return $next($request);
        });
    }
}
