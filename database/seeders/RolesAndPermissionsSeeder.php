<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Standard permissions list across all tenants.
     */
    public static function getStandardPermissions(): array
    {
        return [
            'manage-team',
            'manage-billing',
            'manage-warehouses',
            'manage-products',
            'manage-stock',
            'manage-orders',
            'view-reports',
            'manage-accounting',
            'manage-hr',
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (static::getStandardPermissions() as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }
    }

    /**
     * Helper to seed standard roles and permissions for a given team/tenant.
     */
    public static function seedRolesForTeam(int $teamId): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $allPermissions = static::getStandardPermissions();

        foreach ($allPermissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        // 1. Owner Role - Has all permissions
        $ownerRole = Role::firstOrCreate([
            'name' => 'owner',
            'guard_name' => 'web',
            'team_id' => $teamId,
        ]);
        $ownerRole->syncPermissions($allPermissions);

        // 2. Admin Role - Operational & managerial permissions (excluding billing and team ownership)
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
            'team_id' => $teamId,
        ]);
        $adminRole->syncPermissions([
            'manage-warehouses',
            'manage-products',
            'manage-stock',
            'manage-orders',
            'view-reports',
            'manage-accounting',
            'manage-hr',
        ]);

        // 3. Staff Role - Daily stock operations & orders
        $staffRole = Role::firstOrCreate([
            'name' => 'staff',
            'guard_name' => 'web',
            'team_id' => $teamId,
        ]);
        $staffRole->syncPermissions([
            'manage-stock',
            'manage-orders',
        ]);

        // 4. Editor Role - Alias for staff role for backward compatibility
        $editorRole = Role::firstOrCreate([
            'name' => 'editor',
            'guard_name' => 'web',
            'team_id' => $teamId,
        ]);
        $editorRole->syncPermissions([
            'manage-stock',
            'manage-orders',
        ]);

        // 5. Viewer Role - Read-only reporting access
        $viewerRole = Role::firstOrCreate([
            'name' => 'viewer',
            'guard_name' => 'web',
            'team_id' => $teamId,
        ]);
        $viewerRole->syncPermissions([
            'view-reports',
        ]);
    }
}
