<?php

namespace Tests\Feature\Security;

use App\Models\ChartOfAccount;
use App\Models\Team;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialReportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;

    protected User $ownerA;
    protected User $adminA;
    protected User $staffA;
    protected User $viewerWithPermA;
    protected User $viewerWithoutPermA;

    protected User $ownerB;

    protected ChartOfAccount $accountA;
    protected ChartOfAccount $accountB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Tenant A
        $this->ownerA = User::factory()->create(['name' => 'Owner A', 'email' => 'owner.a@example.com']);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Acme Corporation',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        RolesAndPermissionsSeeder::seedRolesForTeam($this->tenantA->id);
        setPermissionsTeamId($this->tenantA->id);
        $this->ownerA->assignRole('owner');

        // Provision Default Accounts for Tenant A
        app(AccountingService::class)->ensureDefaultAccounts($this->tenantA->id);

        // Admin A
        $this->adminA = User::factory()->create(['name' => 'Admin A', 'email' => 'admin.a@example.com']);
        $this->adminA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        $this->tenantA->users()->attach($this->adminA, ['role' => 'admin']);
        setPermissionsTeamId($this->tenantA->id);
        $this->adminA->assignRole('admin');

        // Staff A (has manage-stock, manage-orders, but NOT manage-accounting and NOT view-reports)
        $this->staffA = User::factory()->create(['name' => 'Staff A', 'email' => 'staff.a@example.com']);
        $this->staffA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        $this->tenantA->users()->attach($this->staffA, ['role' => 'staff']);
        setPermissionsTeamId($this->tenantA->id);
        $this->staffA->assignRole('staff');

        // Viewer With Permission (role: viewer with view-reports)
        $this->viewerWithPermA = User::factory()->create(['name' => 'Viewer With Perm', 'email' => 'viewer.perm@example.com']);
        $this->viewerWithPermA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        $this->tenantA->users()->attach($this->viewerWithPermA, ['role' => 'viewer']);
        setPermissionsTeamId($this->tenantA->id);
        $this->viewerWithPermA->assignRole('viewer');

        // Viewer Without Permission (attached with no roles/permissions)
        $this->viewerWithoutPermA = User::factory()->create(['name' => 'Viewer No Perm', 'email' => 'viewer.noperm@example.com']);
        $this->viewerWithoutPermA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        $this->tenantA->users()->attach($this->viewerWithoutPermA, ['role' => 'viewer']);
        setPermissionsTeamId($this->tenantA->id);
        // Stripped of all permissions and roles
        $this->viewerWithoutPermA->syncRoles([]);
        $this->viewerWithoutPermA->syncPermissions([]);

        // Get an account in Tenant A
        $this->accountA = ChartOfAccount::where('team_id', $this->tenantA->id)->first();

        // 2. Setup Tenant B
        $this->ownerB = User::factory()->create(['name' => 'Owner B', 'email' => 'owner.b@example.com']);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Globex Corporation',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->forceFill(['current_team_id' => $this->tenantB->id])->save();
        RolesAndPermissionsSeeder::seedRolesForTeam($this->tenantB->id);
        setPermissionsTeamId($this->tenantB->id);
        $this->ownerB->assignRole('owner');

        // Provision Default Accounts for Tenant B
        app(AccountingService::class)->ensureDefaultAccounts($this->tenantB->id);
        $this->accountB = ChartOfAccount::where('team_id', $this->tenantB->id)->first();
    }

    /**
     * TEST 1: Viewer with 'view-reports' permission can view financial report statements.
     */
    public function test_viewer_with_report_permission_can_view_financial_reports(): void
    {
        $this->actingAs($this->viewerWithPermA);

        $this->get(route('reports.trial-balance'))->assertOk();
        $this->get(route('reports.profit-loss'))->assertOk();
        $this->get(route('reports.balance-sheet'))->assertOk();
        $this->get(route('reports.general-ledger'))->assertOk();
    }

    /**
     * TEST 2: Viewer with 'view-reports' permission CANNOT access bank reconciliation.
     */
    public function test_viewer_with_report_permission_cannot_access_bank_reconciliation(): void
    {
        $this->actingAs($this->viewerWithPermA);

        // Bank reconciliation requires manage-accounting, which viewer does not have
        $this->get(route('reconciliation.index'))->assertForbidden();
        $this->post(route('reconciliation.store'), [
            'chart_of_account_id' => $this->accountA->id,
            'statement_balance' => 5000,
            'reconciliation_date' => now()->toDateString(),
        ])->assertForbidden();
    }

    /**
     * TEST 3: Viewer without 'view-reports' permission is strictly blocked from all financial reports.
     */
    public function test_viewer_without_report_permission_is_forbidden(): void
    {
        $this->actingAs($this->viewerWithoutPermA);

        $this->get(route('reports.trial-balance'))->assertForbidden();
        $this->get(route('reports.profit-loss'))->assertForbidden();
        $this->get(route('reports.balance-sheet'))->assertForbidden();
        $this->get(route('reports.general-ledger'))->assertForbidden();
        $this->get(route('reconciliation.index'))->assertForbidden();
    }

    /**
     * TEST 4: Staff without accounting permission is strictly blocked from financial reports.
     */
    public function test_staff_without_accounting_permission_is_forbidden(): void
    {
        $this->actingAs($this->staffA);

        $this->get(route('reports.trial-balance'))->assertForbidden();
        $this->get(route('reports.profit-loss'))->assertForbidden();
        $this->get(route('reports.balance-sheet'))->assertForbidden();
        $this->get(route('reports.general-ledger'))->assertForbidden();
        $this->get(route('reconciliation.index'))->assertForbidden();
    }

    /**
     * TEST 5: Admin with appropriate permissions has full access to reports and reconciliation.
     */
    public function test_admin_with_appropriate_permissions_has_full_access(): void
    {
        $this->actingAs($this->adminA);

        $this->get(route('reports.trial-balance'))->assertOk();
        $this->get(route('reports.profit-loss'))->assertOk();
        $this->get(route('reports.balance-sheet'))->assertOk();
        $this->get(route('reports.general-ledger'))->assertOk();
        $this->get(route('reconciliation.index'))->assertOk();
    }

    /**
     * TEST 6: Cross-Tenant Financial Report Access is strictly blocked.
     * Tenant A user cannot query General Ledger drilldown for Tenant B's Chart of Account ID.
     */
    public function test_cross_tenant_financial_report_access_is_blocked(): void
    {
        $this->actingAs($this->ownerA);

        // Attempt to pass Tenant B's account ID into Tenant A's General Ledger report
        $response = $this->get(route('reports.general-ledger', [
            'account_id' => $this->accountB->id,
        ]));

        // Must reject with 403 or 404 (due to TenantScope or policy check)
        $this->assertTrue(in_array($response->status(), [403, 404]), "Expected 403 or 404, got {$response->status()}");
    }

    /**
     * TEST 7: Direct URL access and export PDF endpoints enforce authorization.
     */
    public function test_direct_url_access_and_export_endpoints_enforce_authorization(): void
    {
        // Unauthenticated direct access redirects to login
        $this->get('/accounting/reports/trial-balance/pdf')->assertRedirect('/login');
        $this->get('/accounting/reports/profit-loss/pdf')->assertRedirect('/login');
        $this->get('/accounting/reports/balance-sheet/pdf')->assertRedirect('/login');

        // Staff without accounting permission receives 403
        $this->actingAs($this->staffA);
        $this->get('/accounting/reports/trial-balance/pdf')->assertForbidden();
        $this->get('/accounting/reports/profit-loss/pdf')->assertForbidden();
        $this->get('/accounting/reports/balance-sheet/pdf')->assertForbidden();

        // Viewer with view-reports permission can download reports
        $this->actingAs($this->viewerWithPermA);
        $this->get('/accounting/reports/trial-balance/pdf')->assertOk();
        $this->get('/accounting/reports/profit-loss/pdf')->assertOk();
        $this->get('/accounting/reports/balance-sheet/pdf')->assertOk();

        // Admin can download reports
        $this->actingAs($this->adminA);
        $this->get('/accounting/reports/trial-balance/pdf')->assertOk();
    }
}
