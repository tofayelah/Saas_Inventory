<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckTenantTokenAbility
{
    /**
     * Ability to Spatie permission mappings.
     */
    protected const ABILITY_PERMISSION_MAP = [
        'products:read' => ['manage-products', 'manage-stock', 'manage-orders', 'view-reports'],
        'products:write' => ['manage-products'],
        'customers:read' => ['manage-customers', 'manage-orders'],
        'customers:write' => ['manage-customers'],
        'orders:read' => ['manage-orders', 'view-reports'],
        'orders:write' => ['manage-orders'],
        'documents:read' => ['view-reports', 'manage-accounting', 'manage-orders', 'manage-products'],
        'documents:write' => ['manage-products', 'manage-accounting', 'manage-orders'],
    ];

    /**
     * Handle an incoming request, checking both token ability and user's tenant permissions.
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Authentication required.',
            ], 401);
        }

        // 1. Verify token ability
        $token = $user->currentAccessToken();
        if ($token && method_exists($token, 'can')) {
            if (!$token->can($ability) && !$token->can('*')) {
                return response()->json([
                    'error' => 'TokenAbilityDenied',
                    'message' => "API token does not possess the required ability [{$ability}].",
                ], 403);
            }
        }

        // 2. Verify server-side role/permission in this tenant (unless super admin or owner)
        if (!$user->isSuperAdmin() && !$user->hasRole('owner')) {
            $requiredPermissions = self::ABILITY_PERMISSION_MAP[$ability] ?? [$ability];
            $hasPermission = false;
            foreach ($requiredPermissions as $perm) {
                try {
                    if ($user->hasPermissionTo($perm)) {
                        $hasPermission = true;
                        break;
                    }
                } catch (\Throwable $e) {
                    // Permission does not exist for guard
                }
            }

            if (!$hasPermission) {
                return response()->json([
                    'error' => 'PermissionDenied',
                    'message' => "User does not have the required tenant permission for ability [{$ability}].",
                ], 403);
            }
        }

        return $next($request);
    }
}
