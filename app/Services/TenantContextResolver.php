<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

class TenantContextResolver
{
    /**
     * Resolve the authorized tenant for the incoming HTTP request.
     */
    public function resolve(Request $request): ?Team
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        // Platform Super-Admin in /admin control plane operates in platform mode (null tenant)
        if ($user->isSuperAdmin() && $request->routeIs('admin.*')) {
            return null;
        }

        // Check if super-admin is impersonating a specific tenant
        if ($user->isSuperAdmin() && $request->session()->has('impersonated_tenant_id')) {
            $impersonatedId = (int) $request->session()->get('impersonated_tenant_id');
            $tenant = Team::withoutGlobalScopes()->find($impersonatedId);
            if ($tenant && !$tenant->isSuspended()) {
                return $tenant;
            }
        }

        // Ensure current_team_id is initialized if user has teams
        if (!$user->current_team_id && $user->allTeams()->isNotEmpty()) {
            $user->switchTeam($user->allTeams()->first());
        }

        $team = $user->currentTeam;

        // Verify that the user genuinely belongs to this team
        if ($team && ($user->isSuperAdmin() || $user->belongsToTeam($team))) {
            return $team;
        }

        // If currentTeam is invalid or membership revoked, find first valid team
        $fallback = $user->allTeams()->first();
        if ($fallback) {
            $user->switchTeam($fallback);
            return $fallback;
        }

        return null;
    }
}
