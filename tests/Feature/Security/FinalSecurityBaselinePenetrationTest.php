<?php

namespace Tests\Feature\Security;

use App\Exceptions\TenantContextMissingException;
use App\Models\Client;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SecureStorageService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

class FinalSecurityBaselinePenetrationTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;
    protected User $ownerA;
    protected User $viewerA;
    protected User $staffA;
    protected User $ownerB;
    protected Warehouse $warehouseA;
    protected Warehouse $warehouseB;
    protected Customer $customerA;
    protected Supplier $supplierA;
    protected Client $clientA;
    protected Product $productA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Tenant Alpha
        $this->ownerA = User::factory()->create([
            'name' => 'Owner Alpha',
            'email' => 'owner.alpha.pen@example.com',
            'phone' => '+8801700111111',
        ]);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Alpha Penetration Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->update(['current_team_id' => $this->tenantA->id]);

        setPermissionsTeamId($this->tenantA->id);
        $roleOwnerA = Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantA->id]);
        $this->ownerA->assignRole($roleOwnerA);

        $this->viewerA = User::factory()->create([
            'name' => 'Viewer Alpha',
            'email' => 'viewer.alpha.pen@example.com',
            'phone' => '+8801700111112',
            'current_team_id' => $this->tenantA->id,
        ]);
        $roleViewerA = Role::firstOrCreate(['name' => 'viewer', 'team_id' => $this->tenantA->id]);
        $this->viewerA->assignRole($roleViewerA);

        $this->staffA = User::factory()->create([
            'name' => 'Staff Alpha',
            'email' => 'staff.alpha.pen@example.com',
            'phone' => '+8801700111113',
            'current_team_id' => $this->tenantA->id,
        ]);
        $roleStaffA = Role::firstOrCreate(['name' => 'staff', 'team_id' => $this->tenantA->id]);
        $this->staffA->assignRole($roleStaffA);

        $this->warehouseA = Warehouse::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Hub',
            'code' => 'WH-A-PEN',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->customerA = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Direct Customer',
            'phone' => '+8801711223344',
            'is_active' => true,
        ]);

        $this->supplierA = Supplier::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Primary Supplier',
            'phone' => '+8801711223355',
            'is_active' => true,
        ]);

        $this->clientA = Client::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Enterprise Client',
            'phone' => '+8801711223366',
            'is_active' => true,
        ]);

        $this->productA = Product::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Industrial Sensor',
            'sku' => 'SKU-PEN-A1',
            'cost_price' => 1000.00,
            'unit_price' => 1500.00,
            'is_active' => true,
        ]);

        // 2. Setup Tenant Beta (Adversary)
        $this->ownerB = User::factory()->create([
            'name' => 'Owner Beta (Adversary)',
            'email' => 'owner.beta.pen@example.com',
            'phone' => '+8801700222222',
        ]);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Beta Intruder Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->update(['current_team_id' => $this->tenantB->id]);

        setPermissionsTeamId($this->tenantB->id);
        $roleOwnerB = Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantB->id]);
        $this->ownerB->assignRole($roleOwnerB);

        $this->warehouseB = Warehouse::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Beta Hub',
            'code' => 'WH-B-PEN',
            'is_default' => true,
            'is_active' => true,
        ]);

        app(TenantContext::class)->clear();
    }

    /**
     * PEN-TEST 1: Cross-Tenant IDOR Manipulation Across Critical Resources (Phase 2).
     */
    public function test_cross_tenant_idor_manipulation_on_all_major_business_endpoints(): void
    {
        // Setup Tenant Alpha resources
        $quotationA = Quotation::forceCreate([
            'team_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'quotation_number' => 'QUO-PEN-01',
            'total_amount' => 15000.00,
            'status' => 'draft',
            'quotation_date' => now()->toDateString(),
        ]);

        $projectA = Project::forceCreate([
            'team_id' => $this->tenantA->id,
            'client_id' => $this->clientA->id,
            'project_code' => 'PRJ-PEN-01',
            'name' => 'Confidential Alpha Project',
            'status' => 'planning',
            'budget' => 500000.00,
        ]);

        $salesOrderA = SalesOrder::forceCreate([
            'team_id' => $this->tenantA->id,
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA->id,
            'order_number' => 'SO-PEN-01',
            'total_amount' => 15000.00,
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
        ]);

        $purchaseOrderA = PurchaseOrder::forceCreate([
            'team_id' => $this->tenantA->id,
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'order_number' => 'PO-PEN-01',
            'total_amount' => 10000.00,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
        ]);

        // Adversary (Tenant Beta Owner) attempts to access Tenant Alpha resources
        // 1. Quotation edit attempt
        $resQuo = $this->actingAs($this->ownerB)->get(route('quotations.edit', $quotationA->id));
        $this->assertContains($resQuo->getStatusCode(), [403, 404]);

        // 2. Project access attempt
        $resPrj = $this->actingAs($this->ownerB)->get(route('projects.show', $projectA->id));
        $this->assertContains($resPrj->getStatusCode(), [403, 404]);

        // 3. Sales Order mutation attempt
        $resSo = $this->actingAs($this->ownerB)->post(route('sales.confirm', $salesOrderA->id));
        $this->assertContains($resSo->getStatusCode(), [403, 404]);

        // 4. Purchase Order edit attempt
        $resPo = $this->actingAs($this->ownerB)->get(route('purchases.edit', $purchaseOrderA->id));
        $this->assertContains($resPo->getStatusCode(), [403, 404]);

        // 5. Customer dues IDOR update attempt
        $resCust = $this->actingAs($this->ownerB)->put(route('dues.customer.update', $this->customerA->id), [
            'name' => 'Adversary Tampered Customer',
            'phone' => '+8801700999999',
        ]);
        $this->assertContains($resCust->getStatusCode(), [403, 404]);
    }

    /**
     * PEN-TEST 2: Fail-Closed Tenant Context on Unscoped Execution (Phase 2).
     */
    public function test_fail_closed_tenant_scope_on_missing_context(): void
    {
        $tenantContext = app(TenantContext::class);
        $tenantContext->clear();

        $this->expectException(TenantContextMissingException::class);

        // Direct Eloquent query with no active tenant context must fail closed
        Product::where('is_active', true)->get();
    }

    /**
     * PEN-TEST 3: RBAC Privilege Escalation via Direct Mutation by Viewer (Phase 3).
     */
    public function test_rbac_viewer_role_privilege_escalation_via_direct_post(): void
    {
        // Viewer attempts to create a product
        $resProd = $this->actingAs($this->viewerA)->post(route('products.store'), [
            'name' => 'Illicit Product Insertion',
            'sku' => 'SKU-ILLICIT-01',
            'unit' => 'pcs',
            'cost_price' => 500,
            'selling_price' => 800,
            'reorder_level' => 5,
            'reorder_quantity' => 10,
        ]);
        $this->assertEquals(403, $resProd->getStatusCode());

        // Viewer attempts to create a quotation
        $resQuo = $this->actingAs($this->viewerA)->post(route('quotations.store'), [
            'customer_id' => $this->customerA->id,
            'quotation_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        $this->assertEquals(403, $resQuo->getStatusCode());

        // Viewer attempts to delete a product
        $resDel = $this->actingAs($this->viewerA)->delete(route('products.destroy', $this->productA->id));
        $this->assertEquals(403, $resDel->getStatusCode());
    }

    /**
     * PEN-TEST 4: Staff Role Restricted from Company Settings and Financial Reports (Phase 3).
     */
    public function test_rbac_staff_role_cannot_access_company_settings_or_financial_reports(): void
    {
        // Staff attempts company settings update
        $resSettings = $this->actingAs($this->staffA)->post(route('settings.company.update'), [
            'name' => 'Staff Tampered Company Name',
        ]);
        $this->assertEquals(403, $resSettings->getStatusCode());

        // Staff attempts financial reports index access
        $resReport = $this->actingAs($this->staffA)->get(route('reports.index'));
        $this->assertEquals(403, $resReport->getStatusCode());
    }

    /**
     * PEN-TEST 5: Mass Assignment Parameter Tampering Cannot Override team_id (Phase 2).
     */
    public function test_parameter_tampering_cannot_override_team_id(): void
    {
        // Tenant Alpha attempts to inject team_id = Tenant Beta's ID (or 9999)
        $res = $this->actingAs($this->ownerA)->post(route('products.store'), [
            'team_id' => $this->tenantB->id,
            'name' => 'Tampered Team Product',
            'sku' => 'SKU-TAMPER-01',
            'unit' => 'pcs',
            'cost_price' => 100,
            'selling_price' => 200,
            'reorder_level' => 5,
            'reorder_quantity' => 10,
        ]);

        $res->assertSessionHasNoErrors();

        // Verify record was forcefully bound to Tenant Alpha, NOT Tenant Beta!
        $product = Product::withoutGlobalScopes()->where('sku', 'SKU-TAMPER-01')->first();
        $this->assertNotNull($product);
        $this->assertEquals($this->tenantA->id, $product->team_id);
        $this->assertNotEquals($this->tenantB->id, $product->team_id);
    }

    /**
     * PEN-TEST 6: Storage Path Traversal and Directory Security (Phase 6).
     */
    public function test_storage_path_traversal_and_malicious_filenames(): void
    {
        $service = app(SecureStorageService::class);
        Storage::fake('private');

        // Path traversal in resolveTenantFilePath
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Directory traversal sequences are forbidden.');
        $service->resolveTenantFilePath($this->tenantA->id, '../../etc/passwd');
    }

    /**
     * PEN-TEST 7: Sanctum Token Without Ability is Rejected on API (Phase 6).
     */
    public function test_sanctum_token_without_appropriate_ability_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
        $storedPath = app(SecureStorageService::class)->storeTenantFile($this->tenantA->id, $file, 'documents');

        // Issue token with only 'products:read', lacking 'documents:read'
        $plainToken = $this->ownerA->createTenantToken($this->tenantA->id, 'Catalog Only Token', ['products:read'])->plainTextToken;

        // Attempting API file download (which requires 'documents:read')
        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson(route('api.v1.files.download', ['path' => $storedPath]));

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'TokenAbilityDenied']);
    }

    /**
     * PEN-TEST 8: Queue Worker Tenant Context Isolation Across Multiple Cycles (Phase 7).
     */
    public function test_queue_worker_tenant_context_leakage_across_multiple_iterations(): void
    {
        $tenantContext = app(TenantContext::class);

        // Cycle 1: Job runs in Tenant A context
        $tenantContext->executeAsTenant($this->tenantA->id, function () use ($tenantContext) {
            $this->assertEquals($this->tenantA->id, $tenantContext->getTenantId());
        });

        // Simulating queue worker before hook
        event(new \Illuminate\Queue\Events\JobProcessing('database', new \Illuminate\Queue\Jobs\SyncJob(app(), '{"job": "Job1"}', 'connection', 'default')));
        $this->assertFalse($tenantContext->hasTenant());

        // Cycle 2: Job runs in Tenant B context
        $tenantContext->executeAsTenant($this->tenantB->id, function () use ($tenantContext) {
            $this->assertEquals($this->tenantB->id, $tenantContext->getTenantId());
        });

        // Simulating queue worker after hook
        event(new \Illuminate\Queue\Events\JobProcessed('database', new \Illuminate\Queue\Jobs\SyncJob(app(), '{"job": "Job2"}', 'connection', 'default')));
        $this->assertFalse($tenantContext->hasTenant());
    }
}
