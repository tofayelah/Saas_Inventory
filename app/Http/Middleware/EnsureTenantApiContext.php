<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantApiContext
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Handle an incoming API request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Valid API authentication token is required.',
            ], 401);
        }

        // Verify token-level team binding
        $token = $user->currentAccessToken();
        if ($token && !empty($token->team_id)) {
            $tokenTeam = \App\Models\Team::withoutGlobalScopes()->find($token->team_id);
            if ($tokenTeam && $tokenTeam->isSuspended()) {
                return response()->json([
                    'error' => 'TenantSuspended',
                    'message' => 'This tenant workspace is currently suspended. Please contact platform administration.',
                ], 403);
            }
        }

        $tenant = $this->tenantContext->getTenant();

        if (!$tenant) {
            return response()->json([
                'error' => 'TenantContextMissing',
                'message' => 'No active or authorized tenant context could be resolved for this token.',
            ], 403);
        }

        if ($tenant->isSuspended()) {
            return response()->json([
                'error' => 'TenantSuspended',
                'message' => 'This tenant workspace is currently suspended. Please contact platform administration.',
            ], 403);
        }

        // Verify token-level team binding
        $token = $user->currentAccessToken();
        if ($token && !empty($token->team_id)) {
            if ((int) $token->team_id !== (int) $tenant->id) {
                return response()->json([
                    'error' => 'TenantMismatch',
                    'message' => 'Token tenant binding does not match active tenant context.',
                ], 403);
            }
        }

        // Strict rejection of spoofed client-supplied tenant identifiers
        $clientSuppliedTeam = $request->header('X-Tenant-ID') ?: $request->input('team_id');
        if ($clientSuppliedTeam !== null && (int) $clientSuppliedTeam !== (int) $tenant->id) {
            return response()->json([
                'error' => 'CrossTenantAccessBlocked',
                'message' => 'Client-supplied tenant ID does not match authorized token tenant.',
            ], 403);
        }

        return $next($request);
    }
}
