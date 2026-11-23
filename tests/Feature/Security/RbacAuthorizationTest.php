<?php

namespace Tests\Feature\Security;

use App\Models\Brand;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesOrder;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingService;
use App\Services\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RbacAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;

    protected User $ownerA;
    protected User $adminA;
    protected User $staffA;
    protected User $viewerA;

    protected User $ownerB;

    protected Warehouse $warehouseA;
    protected ProductCategory $categoryA;
    protected Brand $brandA;
    protected Product $productA;
    protected Customer $customerA;
    protected SalesOrder $orderA;
    protected FinancialAccount $accountA;
    protected Employee $employeeA;

    protected Warehouse $warehouseB;
    protected Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Tenant A
        $this->ownerA = User::factory()->create(['name' => 'Owner A', 'email' => 'owner.a@example.com']);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Tenant A Workspace',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        RolesAndPermissionsSeeder::seedRolesForTeam($this->tenantA->id);
        setPermissionsTeamId($this->tenantA->id);
        $this->ownerA->assignRole('owner');

        // Setup Admin A
        $this->adminA = User::factory()->create(['name' => 'Admin A', 'email' => 'admin.a@example.com']);
        $this->adminA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        $this->tenantA->users()->attach($this->adminA, ['role' => 'admin']);
        setPermissionsTeamId($this->tenantA->id);
        $this->adminA->assignRole('admin');

        // Setup Staff A
        $this->staffA = User::factory()->create(['name' => 'Staff A', 'email' => 'staff.a@example.com']);
        $this->staffA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        $this->tenantA->users()->attach($this->staffA, ['role' => 'staff']);
        setPermissionsTeamId($this->tenantA->id);
        $this->staffA->assignRole('staff');

        // Setup Viewer A
        $this->viewerA = User::factory()->create(['name' => 'Viewer A', 'email' => 'viewer.a@example.com']);
        $this->viewerA->forceFill(['current_team_id' => $this->tenantA->id])->save();
        $this->tenantA->users()->attach($this->viewerA, ['role' => 'viewer']);
        setPermissionsTeamId($this->tenantA->id);
        $this->viewerA->assignRole('viewer');

        // Seed Tenant A business data
        app(TenantContext::class)->executeAsTenant($this->tenantA, function () {
            $this->warehouseA = Warehouse::create([
                'name' => 'Tenant A Main Hub',
                'code' => 'TA-WH-01',
                'is_active' => true,
                'is_default' => true,
            ]);

            $this->categoryA = ProductCategory::create([
                'name' => 'Electronics',
            ]);

            $this->brandA = Brand::create([
                'name' => 'Sony',
            ]);

            $this->productA = Product::create([
                'category_id' => $this->categoryA->id,
                'brand_id' => $this->brandA->id,
                'name' => 'Bravia 4K TV',
                'sku' => 'SNY-4K-001',
                'cost_price' => 500.00,
                'unit_price' => 800.00,
                'reorder_level' => 2,
            ]);

            $this->customerA = Customer::create([
                'name' => 'John Doe',
                'phone' => '01711111111',
            ]);

            $this->orderA = SalesOrder::create([
                'order_number' => 'SO-TA-001',
                'order_date' => now()->toDateString(),
                'customer_id' => $this->customerA->id,
                'warehouse_id' => $this->warehouseA->id,
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'subtotal' => 800.00,
                'total_amount' => 800.00,
                'paid_amount' => 0.00,
                'due_amount' => 800.00,
            ]);

            $this->accountA = FinancialAccount::create([
                'name' => 'City Bank Operations',
                'type' => 'bank',
                'opening_balance' => 10000.00,
                'current_balance' => 10000.00,
                'is_active' => true,
            ]);

            $this->employeeA = Employee::create([
                'employee_code' => 'EMP-001',
                'name' => 'Farhan Ahmed',
                'email' => 'farhan@tenanta.test',
                'phone' => '01811111111',
                'designation' => 'Sales Officer',
                'department' => 'Sales',
                'salary' => 35000.00,
                'is_active' => true,
            ]);
        });

        // 2. Setup Tenant B
        $this->ownerB = User::factory()->create(['name' => 'Owner B', 'email' => 'owner.b@example.com']);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Tenant B Workspace',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->forceFill(['current_team_id' => $this->tenantB->id])->save();
        RolesAndPermissionsSeeder::seedRolesForTeam($this->tenantB->id);
        setPermissionsTeamId($this->tenantB->id);
        $this->ownerB->assignRole('owner');

        // Seed Tenant B data
        app(TenantContext::class)->executeAsTenant($this->tenantB, function () {
            $this->warehouseB = Warehouse::create([
                'name' => 'Tenant B Main Hub',
                'code' => 'TB-WH-01',
                'is_active' => true,
                'is_default' => true,
            ]);

            $this->productB = Product::create([
                'name' => 'Dell Monitor',
                'sku' => 'DLL-MON-001',
                'cost_price' => 200.00,
                'unit_price' => 300.00,
            ]);
        });
    }

    /**
     * TEST 1: Tenant Owner has full access across all operations, billing, and team management.
     */
    public function test_owner_has_full_access_to_all_operations_and_settings(): void
    {
        $this->actingAs($this->ownerA);

        // Owner can access dashboard
        $this->get(route('dashboard'))->assertOk();

        // Owner can access warehouses
        $this->get(route('warehouses.index'))->assertOk();

        // Owner can access products
        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.create'))->assertOk();

        // Owner can access accounting
        $this->get(route('bank-accounts.index'))->assertOk();
        $this->get(route('accounts.index'))->assertOk();

        // Owner can access HR & Payroll
        $this->get(route('employees.index'))->assertOk();
        $this->get(route('payroll.index'))->assertOk();

        // Owner can access reports
        $this->get(route('reports.index'))->assertOk();

        // Owner can access company settings
        $this->get(route('settings.company'))->assertOk();

        // Owner can access subscription billing
        $this->get(route('subscription.index'))->assertOk();

        // Owner can access team management
        $this->get(route('settings.team'))->assertOk();
    }

    /**
     * TEST 2: Administrator can manage operations, accounting, and HR, but cannot access billing or manage team.
     */
    public function test_admin_has_operational_access_but_blocked_from_billing_and_team(): void
    {
        $this->actingAs($this->adminA);

        // Admin CAN access operational routes
        $this->get(route('warehouses.index'))->assertOk();
        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.create'))->assertOk();
        $this->get(route('sales.index'))->assertOk();
        $this->get(route('purchases.index'))->assertOk();
        $this->get(route('bank-accounts.index'))->assertOk();
        $this->get(route('employees.index'))->assertOk();
        $this->get(route('reports.index'))->assertOk();
        $this->get(route('settings.company'))->assertOk();

        // Admin CANNOT access subscription billing (manage-billing is owner-only)
        $this->get(route('subscription.index'))->assertForbidden();

        // Admin CANNOT access team settings (manage-team is owner-only)
        $this->get(route('settings.team'))->assertForbidden();
    }

    /**
     * TEST 3: Staff can process orders and inventory, but is blocked from product catalog creation,
     * warehouses, accounting, HR, company settings, and billing.
     */
    public function test_staff_restricted_to_operational_orders_and_stock(): void
    {
        $this->actingAs($this->staffA);

        // Staff CAN access orders & POS
        $this->get(route('sales.index'))->assertOk();
        $this->get(route('pos.index'))->assertOk();
        $this->get(route('purchases.index'))->assertOk();
        $this->get(route('inventory.index'))->assertOk();

        // Staff CAN view product catalog (for order entry), but CANNOT create or mutate products
        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.create'))->assertForbidden();
        $this->post(route('products.store'), [
            'name' => 'Staff Product',
            'sku' => 'STF-001',
            'cost_price' => 10,
            'unit_price' => 20,
        ])->assertForbidden();

        // Staff CANNOT access warehouses management
        $this->get(route('warehouses.index'))->assertForbidden();
        $this->post(route('warehouses.store'), [
            'name' => 'Unauthorized Hub',
            'code' => 'UNAUTH',
        ])->assertForbidden();

        // Staff CANNOT access accounting
        $this->get(route('bank-accounts.index'))->assertForbidden();
        $this->get(route('accounts.index'))->assertForbidden();
        $this->get(route('journal.index'))->assertForbidden();

        // Staff CANNOT access HR & Payroll
        $this->get(route('employees.index'))->assertForbidden();
        $this->get(route('payroll.index'))->assertForbidden();

        // Staff CANNOT access company settings or billing
        $this->get(route('settings.company'))->assertForbidden();
        $this->get(route('subscription.index'))->assertForbidden();
        $this->get(route('settings.team'))->assertForbidden();
    }

    /**
     * TEST 4: Viewer can view reports and read-only catalogs, but is blocked from all mutations.
     */
    public function test_viewer_is_read_only_and_blocked_from_mutations(): void
    {
        $this->actingAs($this->viewerA);

        // Viewer CAN access reports
        $this->get(route('reports.index'))->assertOk();
        $this->get(route('reports.trial-balance'))->assertOk();

        // Viewer CANNOT access warehouses management
        $this->get(route('warehouses.index'))->assertForbidden();

        // Viewer CANNOT create product
        $this->post(route('products.store'), [
            'name' => 'Viewer Product',
            'sku' => 'VWR-001',
            'cost_price' => 10,
            'unit_price' => 20,
        ])->assertForbidden();

        // Viewer CANNOT update product
        $this->put(route('products.update', $this->productA->id), [
            'name' => 'Hacked TV',
            'cost_price' => 10,
            'unit_price' => 20,
        ])->assertForbidden();

        // Viewer CANNOT delete product
        $this->delete(route('products.destroy', $this->productA->id))->assertForbidden();

        // Viewer CANNOT create sales order
        $this->get(route('sales.index'))->assertForbidden();
        $this->post(route('sales.store'), [
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA->id,
        ])->assertForbidden();

        // Viewer CANNOT create employee or run payroll
        $this->get(route('employees.index'))->assertForbidden();
        $this->post(route('payroll.generate'), [
            'month' => now()->format('Y-m'),
        ])->assertForbidden();

        // Viewer CANNOT access settings or billing
        $this->get(route('settings.company'))->assertForbidden();
        $this->get(route('subscription.index'))->assertForbidden();
        $this->get(route('settings.team'))->assertForbidden();
    }

    /**
     * TEST 5: Cross-Tenant IDOR Attack Defense:
     * Tenant A Owner attempting to read, update, or delete Tenant B's product or warehouse returns 404/403.
     */
    public function test_cross_tenant_idor_access_is_strictly_blocked(): void
    {
        $this->actingAs($this->ownerA);

        // Attempt to edit Tenant B's product
        $responseEdit = $this->get(route('products.edit', $this->productB->id));
        $this->assertTrue(in_array($responseEdit->status(), [404, 403]));

        // Attempt to update Tenant B's product
        $responseUpdate = $this->put(route('products.update', $this->productB->id), [
            'name' => 'Hijacked Monitor',
            'cost_price' => 50,
            'unit_price' => 100,
        ]);
        $this->assertTrue(in_array($responseUpdate->status(), [404, 403]));

        // Attempt to delete Tenant B's product
        $responseDelete = $this->delete(route('products.destroy', $this->productB->id));
        $this->assertTrue(in_array($responseDelete->status(), [404, 403]));

        // Attempt to update Tenant B's warehouse
        $responseWarehouse = $this->put(route('warehouses.update', $this->warehouseB->id), [
            'name' => 'Hijacked Warehouse',
            'code' => 'HIJACK',
        ]);
        $this->assertTrue(in_array($responseWarehouse->status(), [404, 403]));
    }

    /**
     * TEST 6: Changing record ID in URL cannot bypass authorization.
     */
    public function test_changing_record_id_cannot_bypass_authorization(): void
    {
        $this->actingAs($this->staffA);

        // Staff attempts to delete a product by specifying valid product ID
        $this->delete(route('products.destroy', $this->productA->id))->assertForbidden();

        // Staff attempts to delete with arbitrary/non-existent ID
        $responseArbitrary = $this->delete("/products/999999");
        $this->assertTrue(in_array($responseArbitrary->status(), [404, 403]));
    }

    /**
     * TEST 7: Direct URL access to sensitive settings is protected by route-level middleware.
     */
    public function test_direct_url_access_to_settings_enforces_middleware(): void
    {
        // Unauthenticated access redirects to login
        $this->get('/settings/company')->assertRedirect('/login');
        $this->get('/settings/subscription')->assertRedirect('/login');
        $this->get('/settings/team')->assertRedirect('/login');

        // Staff access is forbidden (403)
        $this->actingAs($this->staffA);
        $this->get('/settings/company')->assertForbidden();
        $this->get('/settings/subscription')->assertForbidden();
        $this->get('/settings/team')->assertForbidden();
    }

    /**
     * TEST 8: Export and report download endpoints enforce authorization.
     */
    public function test_export_and_download_endpoints_enforce_authorization(): void
    {
        // Catalog export requires manage-products
        $this->actingAs($this->staffA);
        $this->get(route('products.export'))->assertForbidden();

        $this->actingAs($this->viewerA);
        $this->get(route('products.export'))->assertForbidden();

        $this->actingAs($this->adminA);
        $this->get(route('products.export'))->assertOk();

        // Reports export allows view-reports
        $this->actingAs($this->viewerA);
        $this->get(route('reports.pdf'))->assertOk();
    }

    /**
     * TEST 9: Permission Cache Invalidation:
     * When a team member's role is updated, the permission cache is cleared immediately and
     * the new permissions take effect without a stale cache window.
     */
    public function test_permission_cache_invalidates_immediately_on_role_change(): void
    {
        // 1. Initially, viewerA cannot access warehouses (403)
        $this->actingAs($this->viewerA);
        $this->get(route('warehouses.index'))->assertForbidden();

        // 2. Owner promotes viewerA to admin via TeamMemberController
        $this->actingAs($this->ownerA);
        $response = $this->put(route('settings.team.members.update', $this->viewerA->id), [
            'role' => 'admin',
        ]);
        $response->assertRedirect();

        // 3. viewerA (now admin) immediately has access to warehouses without cache staleness
        $this->actingAs($this->viewerA->fresh());
        $this->get(route('warehouses.index'))->assertOk();

        // 4. Owner demotes the user back to viewer
        $this->actingAs($this->ownerA);
        $this->put(route('settings.team.members.update', $this->viewerA->id), [
            'role' => 'viewer',
        ])->assertRedirect();

        // 5. User immediately loses access to warehouses (403)
        $this->actingAs($this->viewerA->fresh());
        $this->get(route('warehouses.index'))->assertForbidden();
    }
}
