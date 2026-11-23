<?php

namespace App\Services;

use App\Models\Sanctum\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

class TenantContextResolver
{
    /**
     * Resolve the authorized tenant for the incoming HTTP request.
     */
    public function resolve(Request $request): ?Team
    {
        $user = $request->user();

        // 1. If user is not yet bound on request, attempt Bearer token resolution for API calls
        if (!$user && $request->bearerToken()) {
            $tokenModel = Sanctum::$personalAccessTokenModel ?? PersonalAccessToken::class;
            $token = $tokenModel::findToken($request->bearerToken());

            if ($token && $token->tokenable instanceof User) {
                if (!$token->expires_at || $token->expires_at->isFuture()) {
                    $user = $token->tokenable;
                    $user->withAccessToken($token);
                }
            }
        }

        if (!$user) {
            return null;
        }

        // 2. Sanctum API Token Tenant Binding (Gate 3)
        // If request is authenticated via a personal access token, enforce the token's bound team
        $accessToken = $user->currentAccessToken();
        if ($accessToken && $accessToken instanceof \Laravel\Sanctum\PersonalAccessToken && !empty($accessToken->team_id)) {
            $tokenTeam = Team::withoutGlobalScopes()->find($accessToken->team_id);
            if ($tokenTeam && !$tokenTeam->isSuspended() && ($user->isSuperAdmin() || $user->belongsToTeam($tokenTeam))) {
                // Align user and Spatie permission team context strictly to the token's tenant
                setPermissionsTeamId($tokenTeam->id);
                $user->forceFill(['current_team_id' => $tokenTeam->id]);
                return $tokenTeam;
            }

            // Token is bound to a deleted, suspended, or unassigned team -> fail closed
            return null;
        }

        // 3. Platform Super-Admin in /admin control plane operates in platform mode (null tenant)
        if ($user->isSuperAdmin() && $request->routeIs('admin.*')) {
            return null;
        }

        // 4. Super-Admin impersonating a specific tenant
        if ($user->isSuperAdmin() && $request->hasSession() && $request->session()->has('impersonated_tenant_id')) {
            $impersonatedId = (int) $request->session()->get('impersonated_tenant_id');
            $tenant = Team::withoutGlobalScopes()->find($impersonatedId);
            if ($tenant && !$tenant->isSuspended()) {
                setPermissionsTeamId($tenant->id);
                return $tenant;
            }
        }

        // 5. Ensure current_team_id is initialized if user has teams
        if (!$user->current_team_id && $user->allTeams()->isNotEmpty()) {
            $user->switchTeam($user->allTeams()->first());
        }

        $team = $user->currentTeam;

        // 6. Verify that the user genuinely belongs to this team
        if ($team && ($user->isSuperAdmin() || $user->belongsToTeam($team))) {
            setPermissionsTeamId($team->id);
            return $team;
        }

        // 7. If currentTeam is invalid or membership revoked, fallback to first valid team
        $fallback = $user->allTeams()->first();
        if ($fallback) {
            $user->switchTeam($fallback);
            setPermissionsTeamId($fallback->id);
            return $fallback;
        }

        return null;
    }
}
