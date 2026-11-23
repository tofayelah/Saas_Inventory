<?php

namespace Tests\Feature\Security;

use App\Models\SecurityAuditLog;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SuperAdminImpersonationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $tenantAdmin;
    protected User $tenantOwner;
    protected Team $teamA;
    protected Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Platform Super-Admin
        $this->superAdmin = User::factory()->create([
            'name' => 'Platform Super Admin',
            'email' => 'superadmin@platform.test',
            'is_super_admin' => true,
        ]);

        // 2. Setup Tenant A with Owner and Admin
        $this->tenantOwner = User::factory()->create([
            'name' => 'Tenant A Owner',
            'email' => 'owner@tenanta.test',
            'is_super_admin' => false,
        ]);
        $this->teamA = Team::forceCreate([
            'user_id' => $this->tenantOwner->id,
            'name' => 'Acme Holdings Ltd',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->tenantOwner->forceFill(['current_team_id' => $this->teamA->id])->save();
        RolesAndPermissionsSeeder::seedRolesForTeam($this->teamA->id);
        setPermissionsTeamId($this->teamA->id);
        $this->tenantOwner->assignRole('owner');

        // Tenant Admin
        $this->tenantAdmin = User::factory()->create([
            'name' => 'Tenant A Admin',
            'email' => 'admin@tenanta.test',
            'is_super_admin' => false,
        ]);
        $this->tenantAdmin->forceFill(['current_team_id' => $this->teamA->id])->save();
        $this->teamA->users()->attach($this->tenantAdmin, ['role' => 'admin']);
        setPermissionsTeamId($this->teamA->id);
        $this->tenantAdmin->assignRole('admin');

        // 3. Setup Tenant B
        $ownerB = User::factory()->create(['name' => 'Owner B', 'is_super_admin' => false]);
        $this->teamB = Team::forceCreate([
            'user_id' => $ownerB->id,
            'name' => 'Beta Global Enterprises',
            'personal_team' => false,
            'status' => 'active',
        ]);
        RolesAndPermissionsSeeder::seedRolesForTeam($this->teamB->id);
    }

    /**
     * TEST 1: Super-Admin impersonating a tenant workspace creates a structured security audit record.
     */
    public function test_super_admin_impersonation_start_creates_audit_log(): void
    {
        $this->actingAs($this->superAdmin);

        $initialCount = SecurityAuditLog::count();

        $response = $this->post(route('admin.tenants.switch', $this->teamA->id), [
            'reason' => 'Customer Support Ticket #4082 - Investigating Inventory Mismatch',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertEquals($this->teamA->id, session('impersonated_tenant_id'));
        $this->assertEquals($this->teamA->id, $this->superAdmin->fresh()->current_team_id);

        // Assert audit log was created
        $this->assertEquals($initialCount + 1, SecurityAuditLog::count());

        $auditLog = SecurityAuditLog::latest('id')->first();
        $this->assertNotNull($auditLog);
        $this->assertEquals($this->superAdmin->id, $auditLog->user_id);
        $this->assertEquals($this->teamA->id, $auditLog->target_team_id);
        $this->assertEquals('impersonation_started', $auditLog->event_type);
        $this->assertEquals('Customer Support Ticket #4082 - Investigating Inventory Mismatch', $auditLog->reason);
        $this->assertNotNull($auditLog->created_at);
        $this->assertNotNull($auditLog->context);
        $this->assertEquals($this->superAdmin->email, $auditLog->context['super_admin_email']);
        $this->assertEquals('Acme Holdings Ltd', $auditLog->context['target_tenant_name']);
    }

    /**
     * TEST 2: Super-Admin exiting impersonated workspace records an impersonation termination audit record.
     */
    public function test_super_admin_impersonation_termination_creates_audit_log(): void
    {
        $this->actingAs($this->superAdmin);

        // Start impersonation
        $this->post(route('admin.tenants.switch', $this->teamA->id), [
            'reason' => 'Routine Audit Investigation',
        ]);

        $this->assertEquals($this->teamA->id, session('impersonated_tenant_id'));

        // Terminate impersonation
        $exitResponse = $this->post(route('admin.exit-tenant'));

        $exitResponse->assertRedirect(route('admin.dashboard'));
        $this->assertNull(session('impersonated_tenant_id'));
        $this->assertNull($this->superAdmin->fresh()->current_team_id);

        // Verify termination log
        $terminationLog = SecurityAuditLog::where('event_type', 'impersonation_terminated')->latest('id')->first();
        $this->assertNotNull($terminationLog);
        $this->assertEquals($this->superAdmin->id, $terminationLog->user_id);
        $this->assertEquals($this->teamA->id, $terminationLog->target_team_id);
        $this->assertEquals('Exited tenant workspace to Platform Super-Admin mode', $terminationLog->reason);
        $this->assertNotNull($terminationLog->context['terminated_at']);
    }

    /**
     * TEST 3: Ordinary tenant administrators cannot trigger impersonation or generate audit events.
     */
    public function test_ordinary_tenant_admin_cannot_trigger_impersonation(): void
    {
        $this->actingAs($this->tenantAdmin);

        $initialCount = SecurityAuditLog::count();

        // Attempt to call switch tenant route
        $response = $this->post(route('admin.tenants.switch', $this->teamB->id), [
            'reason' => 'Malicious Impersonation Attempt',
        ]);

        // Blocked by EnsureSuperAdmin middleware
        $response->assertForbidden();

        // No audit event generated
        $this->assertEquals($initialCount, SecurityAuditLog::count());
    }

    /**
     * TEST 4: Ordinary tenant owner cannot trigger impersonation.
     */
    public function test_ordinary_tenant_owner_cannot_trigger_impersonation(): void
    {
        $this->actingAs($this->tenantOwner);

        $initialCount = SecurityAuditLog::count();

        $response = $this->post(route('admin.tenants.switch', $this->teamB->id));

        $response->assertForbidden();
        $this->assertEquals($initialCount, SecurityAuditLog::count());
    }

    /**
     * TEST 5: Impersonated tenant context does not give access to modify audit records.
     */
    public function test_audit_logs_cannot_be_manipulated_by_tenant_users(): void
    {
        // Create an existing audit record
        $auditLog = SecurityAuditLog::create([
            'user_id' => $this->superAdmin->id,
            'target_team_id' => $this->teamA->id,
            'event_type' => 'impersonation_started',
            'reason' => 'Verified Audit Entry',
            'created_at' => now(),
        ]);

        // Tenant owner logged in
        $this->actingAs($this->tenantOwner);

        // There are no routes or mechanisms for tenant users to access or modify security_audit_logs
        $this->get('/security-audit-logs')->assertNotFound();
        $this->delete("/security-audit-logs/{$auditLog->id}")->assertNotFound();

        // Audit log remains unchanged
        $this->assertDatabaseHas('security_audit_logs', [
            'id' => $auditLog->id,
            'reason' => 'Verified Audit Entry',
        ]);
    }
}
