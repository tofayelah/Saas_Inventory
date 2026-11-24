<?php

namespace App\Services;

use App\Models\SecurityAuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SecurityAuditService
{
    /**
     * Record the initiation of tenant workspace impersonation by a Platform Super-Admin.
     */
    public function logImpersonationStart(User $superAdmin, Team $targetTeam, ?string $reason = null, ?Request $request = null): SecurityAuditLog
    {
        if (!$superAdmin->isSuperAdmin()) {
            throw new AccessDeniedHttpException('Only verified platform super-administrators can initiate workspace impersonation.');
        }

        $ipAddress = $request?->ip() ?: 'CLI/Internal';
        $userAgent = $request?->userAgent() ?: 'Unknown';
        $sessionId = $request && $request->hasSession() ? $request->session()->getId() : null;
        $reasonText = $reason ?: 'Platform Super-Admin Workspace Impersonation';

        $context = [
            'super_admin_email' => $superAdmin->email,
            'super_admin_name' => $superAdmin->name,
            'target_tenant_name' => $targetTeam->name,
            'target_tenant_id' => $targetTeam->id,
            'initiated_at' => now()->toIso8601String(),
        ];

        // 1. Write structured JSON log to application security log channel
        Log::info('SECURITY_AUDIT: Platform super-admin started tenant impersonation', [
            'event' => 'impersonation_started',
            'super_admin_id' => $superAdmin->id,
            'target_team_id' => $targetTeam->id,
            'reason' => $reasonText,
            'ip_address' => $ipAddress,
            'session_id' => $sessionId,
            'context' => $context,
        ]);

        // 2. Persist to immutable platform audit log table
        return SecurityAuditLog::create([
            'user_id' => $superAdmin->id,
            'target_team_id' => $targetTeam->id,
            'event_type' => 'impersonation_started',
            'reason' => $reasonText,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'session_id' => $sessionId,
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    /**
     * Record the termination of tenant workspace impersonation by a Platform Super-Admin.
     */
    public function logImpersonationEnd(User $superAdmin, int $targetTeamId, ?Request $request = null): SecurityAuditLog
    {
        if (!$superAdmin->isSuperAdmin()) {
            throw new AccessDeniedHttpException('Only verified platform super-administrators can terminate workspace impersonation.');
        }

        $ipAddress = $request?->ip() ?: 'CLI/Internal';
        $userAgent = $request?->userAgent() ?: 'Unknown';
        $sessionId = $request && $request->hasSession() ? $request->session()->getId() : null;

        $context = [
            'super_admin_email' => $superAdmin->email,
            'super_admin_name' => $superAdmin->name,
            'target_tenant_id' => $targetTeamId,
            'terminated_at' => now()->toIso8601String(),
        ];

        // 1. Write structured JSON log to application security log channel
        Log::info('SECURITY_AUDIT: Platform super-admin terminated tenant impersonation', [
            'event' => 'impersonation_terminated',
            'super_admin_id' => $superAdmin->id,
            'target_team_id' => $targetTeamId,
            'ip_address' => $ipAddress,
            'session_id' => $sessionId,
            'context' => $context,
        ]);

        // 2. Persist to immutable platform audit log table
        return SecurityAuditLog::create([
            'user_id' => $superAdmin->id,
            'target_team_id' => $targetTeamId,
            'event_type' => 'impersonation_terminated',
            'reason' => 'Exited tenant workspace to Platform Super-Admin mode',
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'session_id' => $sessionId,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
