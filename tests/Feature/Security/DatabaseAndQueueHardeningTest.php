<?php

namespace Tests\Feature\Security;

use App\Jobs\Middleware\TenantAwareJobMiddleware;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\DeliveryChallan;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectBill;
use App\Models\Quotation;
use App\Models\ServiceTicket;
use App\Models\SubscriptionPayment;
use App\Models\Team;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SecureStorageService;
use App\Services\SubscriptionService;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

class DatabaseAndQueueHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;
    protected User $ownerA;
    protected User $ownerB;
    protected Warehouse $warehouseA;
    protected Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Tenant A
        $this->ownerA = User::factory()->create([
            'name' => 'Owner Tenant Alpha',
            'email' => 'owner.alpha@example.com',
            'phone' => '+8801700000001',
        ]);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Tenant Alpha Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->update(['current_team_id' => $this->tenantA->id]);

        setPermissionsTeamId($this->tenantA->id);
        $roleOwnerA = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantA->id]);
        $this->ownerA->assignRole($roleOwnerA);

        $this->warehouseA = Warehouse::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Central Hub',
            'code' => 'WH-A1',
            'is_default' => true,
            'is_active' => true,
        ]);

        // 2. Setup Tenant B
        $this->ownerB = User::factory()->create([
            'name' => 'Owner Tenant Beta',
            'email' => 'owner.beta@example.com',
            'phone' => '+8801700000002',
        ]);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Tenant Beta Ltd',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->update(['current_team_id' => $this->tenantB->id]);

        setPermissionsTeamId($this->tenantB->id);
        $roleOwnerB = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantB->id]);
        $this->ownerB->assignRole($roleOwnerB);

        $this->warehouseB = Warehouse::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Beta Central Hub',
            'code' => 'WH-B1',
            'is_default' => true,
            'is_active' => true,
        ]);

        // Ensure clean tenant context initially
        app(TenantContext::class)->clear();
    }

    /**
     * TEST 1: Concurrency-Safe Payment Approval & Idempotency (SEC-G4-PRI-01).
     */
    public function test_concurrent_payment_approval_is_idempotent_and_safe(): void
    {
        Config::set('services.sslcommerz.sandbox', true);

        $plan = Plan::forceCreate([
            'name' => 'Enterprise Plan',
            'slug' => 'ent-concur',
            'price_bdt' => 5000,
            'annual_price_bdt' => 50000,
            'max_products' => -1,
            'max_warehouses' => -1,
            'max_users' => -1,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-CONCUR-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 5000.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        // Mock Order Validation API
        Http::fake([
            '*/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALIDATED',
                'tran_id' => 'PAY-CONCUR-001',
                'val_id' => 'VAL-CONCUR-999',
                'amount' => '5000.00',
                'currency' => 'BDT',
            ], 200),
        ]);

        // First execution (e.g. IPN callback)
        $responseIpn = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-CONCUR-001',
            'val_id' => 'VAL-CONCUR-999',
            'amount' => 5000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $responseIpn->assertOk();
        $responseIpn->assertJsonFragment(['status' => 'success']);

        $payment->refresh();
        $this->assertEquals('approved', $payment->status);
        $this->assertEquals('VAL-CONCUR-999', $payment->trx_id);

        /** @var TenantSubscription $sub */
        $sub = TenantSubscription::where('team_id', $this->tenantA->id)->first();
        $this->assertNotNull($sub);
        $firstEndsAt = $sub->ends_at;

        // Second simultaneous/duplicate execution (e.g. customer browser redirect arriving after or during IPN)
        $responseSuccess = $this->post(route('sslcommerz.success'), [
            'tran_id' => 'PAY-CONCUR-001',
            'val_id' => 'VAL-CONCUR-999',
            'amount' => 5000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $responseSuccess->assertRedirect(route('subscription.index'));
        $responseSuccess->assertSessionHas('success');

        // Third execution (duplicate IPN webhook)
        $responseDuplicateIpn = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-CONCUR-001',
            'val_id' => 'VAL-CONCUR-999',
            'amount' => 5000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $responseDuplicateIpn->assertOk();
        $responseDuplicateIpn->assertJsonFragment(['status' => 'already_processed']);

        // Verify subscription was NOT extended twice!
        $sub->refresh();
        $this->assertEquals($firstEndsAt->toDateTimeString(), $sub->ends_at->toDateTimeString());
    }

    /**
     * TEST 2: Failed Payment Validation Rolls Back and Does Not Activate Subscription (SEC-G4-PRI-01).
     */
    public function test_failed_payment_validation_rolls_back_and_rejects(): void
    {
        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro-fail-concur',
            'price_bdt' => 3000,
            'annual_price_bdt' => 30000,
            'max_products' => 1000,
            'max_warehouses' => 3,
            'max_users' => 5,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-FAIL-ROLLBACK-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 3000.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        // Mock Order Validation API returning FAILED
        Http::fake([
            '*/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'FAILED',
                'tran_id' => 'PAY-FAIL-ROLLBACK-001',
                'val_id' => 'VAL-FAILED-001',
                'amount' => '3000.00',
                'currency' => 'BDT',
            ], 200),
        ]);

        $response = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-FAIL-ROLLBACK-001',
            'val_id' => 'VAL-FAILED-001',
            'amount' => 3000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $response->assertStatus(422);

        $payment->refresh();
        $this->assertEquals('rejected', $payment->status);

        // Verify no subscription was activated
        $sub = TenantSubscription::where('team_id', $this->tenantA->id)->first();
        $this->assertNull($sub);
    }

    /**
     * TEST 3: Composite Unique Constraints on Quotations (SEC-G4-PRI-02).
     */
    public function test_composite_unique_constraint_on_quotations(): void
    {
        $customerA = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Customer A',
            'phone' => '+8801711111111',
        ]);
        $customerB = Customer::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Customer B',
            'phone' => '+8801722222222',
        ]);

        // 1. Tenant A creates QUO-2026-0001
        $quoA = Quotation::forceCreate([
            'team_id' => $this->tenantA->id,
            'quotation_number' => 'QUO-2026-0001',
            'customer_id' => $customerA->id,
            'quotation_date' => now(),
            'status' => 'draft',
            'total_amount' => 1000.00,
        ]);
        $this->assertNotNull($quoA->id);

        // 2. Tenant B can ALSO create QUO-2026-0001 (Cross-tenant uniqueness permitted)
        $quoB = Quotation::forceCreate([
            'team_id' => $this->tenantB->id,
            'quotation_number' => 'QUO-2026-0001',
            'customer_id' => $customerB->id,
            'quotation_date' => now(),
            'status' => 'draft',
            'total_amount' => 2000.00,
        ]);
        $this->assertNotNull($quoB->id);

        // 3. Tenant A CANNOT create a duplicate QUO-2026-0001 within Tenant A
        $this->expectException(QueryException::class);
        Quotation::forceCreate([
            'team_id' => $this->tenantA->id,
            'quotation_number' => 'QUO-2026-0001',
            'customer_id' => $customerA->id,
            'quotation_date' => now(),
            'status' => 'draft',
            'total_amount' => 1500.00,
        ]);
    }

    /**
     * TEST 4: Composite Unique Constraints on Delivery Challans (SEC-G4-PRI-02).
     */
    public function test_composite_unique_constraint_on_delivery_challans(): void
    {
        $customerA = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Challan Customer A',
            'phone' => '+8801755555555',
        ]);
        $customerB = Customer::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Challan Customer B',
            'phone' => '+8801766666666',
        ]);

        $orderA = \App\Models\SalesOrder::forceCreate([
            'team_id' => $this->tenantA->id,
            'customer_id' => $customerA->id,
            'warehouse_id' => $this->warehouseA->id,
            'order_number' => 'ORD-A-001',
            'order_date' => now(),
            'status' => 'confirmed',
            'total_amount' => 500,
        ]);
        $orderB = \App\Models\SalesOrder::forceCreate([
            'team_id' => $this->tenantB->id,
            'customer_id' => $customerB->id,
            'warehouse_id' => $this->warehouseB->id,
            'order_number' => 'ORD-B-001',
            'order_date' => now(),
            'status' => 'confirmed',
            'total_amount' => 500,
        ]);

        // 1. Tenant A creates CHL-2026-0001
        $chlA = DeliveryChallan::forceCreate([
            'team_id' => $this->tenantA->id,
            'sales_order_id' => $orderA->id,
            'warehouse_id' => $this->warehouseA->id,
            'challan_number' => 'CHL-2026-0001',
            'dispatch_date' => now(),
            'status' => 'draft',
        ]);
        $this->assertNotNull($chlA->id);

        // 2. Tenant B can ALSO create CHL-2026-0001
        $chlB = DeliveryChallan::forceCreate([
            'team_id' => $this->tenantB->id,
            'sales_order_id' => $orderB->id,
            'warehouse_id' => $this->warehouseB->id,
            'challan_number' => 'CHL-2026-0001',
            'dispatch_date' => now(),
            'status' => 'draft',
        ]);
        $this->assertNotNull($chlB->id);

        // 3. Tenant A CANNOT duplicate CHL-2026-0001 within Tenant A
        $this->expectException(QueryException::class);
        DeliveryChallan::forceCreate([
            'team_id' => $this->tenantA->id,
            'sales_order_id' => $orderA->id,
            'warehouse_id' => $this->warehouseA->id,
            'challan_number' => 'CHL-2026-0001',
            'dispatch_date' => now(),
            'status' => 'draft',
        ]);
    }

    /**
     * TEST 5: Composite Unique Constraints on Service Tickets (SEC-G4-PRI-02).
     */
    public function test_composite_unique_constraint_on_service_tickets(): void
    {
        $customerA = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Service Customer A',
            'phone' => '+8801733333333',
        ]);
        $customerB = Customer::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Service Customer B',
            'phone' => '+8801744444444',
        ]);

        // 1. Tenant A creates SRV-2026-0001
        $srvA = ServiceTicket::forceCreate([
            'team_id' => $this->tenantA->id,
            'ticket_number' => 'SRV-2026-0001',
            'customer_id' => $customerA->id,
            'device_name' => 'Laptop A',
            'problem_description' => 'Display issue',
            'received_date' => now(),
            'status' => 'received',
        ]);
        $this->assertNotNull($srvA->id);

        // 2. Tenant B can ALSO create SRV-2026-0001
        $srvB = ServiceTicket::forceCreate([
            'team_id' => $this->tenantB->id,
            'ticket_number' => 'SRV-2026-0001',
            'customer_id' => $customerB->id,
            'device_name' => 'Laptop B',
            'problem_description' => 'Keyboard issue',
            'received_date' => now(),
            'status' => 'received',
        ]);
        $this->assertNotNull($srvB->id);

        // 3. Tenant A CANNOT duplicate SRV-2026-0001 within Tenant A
        $this->expectException(QueryException::class);
        ServiceTicket::forceCreate([
            'team_id' => $this->tenantA->id,
            'ticket_number' => 'SRV-2026-0001',
            'customer_id' => $customerA->id,
            'device_name' => 'Duplicate Item',
            'problem_description' => 'Duplicate test',
            'received_date' => now(),
            'status' => 'received',
        ]);
    }

    /**
     * TEST 6: Activity Logging Tenant Isolation & Platform Event Preservation (SEC-G4-PRI-03).
     */
    public function test_activity_log_tenant_isolation_and_platform_events(): void
    {
        $tenantContext = app(TenantContext::class);

        // 1. Log activity in Tenant A context
        $tenantContext->executeAsTenant($this->tenantA->id, function () {
            activity('inventory')
                ->causedBy($this->ownerA)
                ->log('Stock updated for product Alpha');
        });

        // 2. Log activity in Tenant B context
        $tenantContext->executeAsTenant($this->tenantB->id, function () {
            activity('inventory')
                ->causedBy($this->ownerB)
                ->log('Stock updated for product Beta');
        });

        // 3. Log a platform super-admin security event (unscoped, team_id = null)
        $tenantContext->clear();
        activity('security')
            ->withProperties(['event' => 'super_admin_impersonation'])
            ->log('Super-Admin initiated tenant workspace switch');

        // Verify Tenant A activity
        $activitiesA = Activity::forTenant($this->tenantA->id)->get();
        $this->assertCount(1, $activitiesA);
        $this->assertEquals('Stock updated for product Alpha', $activitiesA->first()->description);
        $this->assertEquals($this->tenantA->id, $activitiesA->first()->team_id);

        // Verify Tenant B activity
        $activitiesB = Activity::forTenant($this->tenantB->id)->get();
        $this->assertCount(1, $activitiesB);
        $this->assertEquals('Stock updated for product Beta', $activitiesB->first()->description);
        $this->assertEquals($this->tenantB->id, $activitiesB->first()->team_id);

        // Verify Platform event preservation
        $platformLogs = Activity::platformOnly()->get();
        $this->assertCount(1, $platformLogs);
        $this->assertEquals('Super-Admin initiated tenant workspace switch', $platformLogs->first()->description);
        $this->assertNull($platformLogs->first()->team_id);
    }

    /**
     * TEST 7: Queue Worker Tenant Context Isolation & Cleanup (SEC-G4-PRI-05).
     */
    public function test_queue_lifecycle_clears_tenant_context_between_jobs(): void
    {
        $tenantContext = app(TenantContext::class);

        // Set tenant context simulating an uncleaned job
        $tenantContext->setTenant($this->tenantA);
        $this->assertTrue($tenantContext->hasTenant());
        $this->assertEquals($this->tenantA->id, $tenantContext->getTenantId());

        // Dispatch Queue::before / Queue::looping event
        event(new \Illuminate\Queue\Events\JobProcessing('database', new \Illuminate\Queue\Jobs\SyncJob(app(), '{"job": "DummyJob"}', 'connection', 'default')));

        // Verify TenantContext is cleared
        $this->assertFalse($tenantContext->hasTenant());
        $this->assertNull($tenantContext->getTenantId());
    }

    /**
     * TEST 8: Hard In-Service File Size Ceiling in SecureStorageService (SEC-G3-POST-03).
     */
    public function test_secure_storage_service_rejects_files_exceeding_size_ceiling(): void
    {
        $service = app(SecureStorageService::class);

        // Create an uploaded file exceeding 15MB (e.g. 16MB = 16384 KB)
        $oversizedFile = UploadedFile::fake()->create('huge_archive.zip', 16 * 1024 + 100, 'application/zip');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Uploaded file exceeds the maximum allowed in-service size limit of 15MB.');

        $service->storeTenantFile($this->tenantA->id, $oversizedFile, 'documents');
    }

    /**
     * TEST 9: Authenticated Private Letterhead Preview Stream (SEC-G3-POST-02).
     */
    public function test_authenticated_tenant_owner_can_stream_private_letterhead(): void
    {
        Storage::fake('private');

        $letterhead = UploadedFile::fake()->image('custom_inv_bg.png', 800, 1200);
        $storedPath = $letterhead->store("tenants/{$this->tenantA->id}/letterheads", 'private');

        $this->tenantA->update(['invoice_bg_path' => $storedPath]);

        // 1. Authenticated Owner of Tenant A can stream their own letterhead
        $responseA = $this->actingAs($this->ownerA)->get(route('settings.company.letterhead', ['type' => 'invoice']));
        $responseA->assertOk();
        $responseA->assertHeader('X-Content-Type-Options', 'nosniff');

        // 2. Owner of Tenant B cannot stream Tenant A's letterhead (Tenant B has no letterhead configured)
        $responseB = $this->actingAs($this->ownerB)->get(route('settings.company.letterhead', ['type' => 'invoice']));
        $responseB->assertStatus(404);
    }

    /**
     * TEST 10: Unauthenticated User Cannot Stream Private Letterhead (SEC-G3-POST-02).
     */
    public function test_unauthenticated_user_cannot_stream_private_letterhead(): void
    {
        $response = $this->get(route('settings.company.letterhead', ['type' => 'invoice']));
        $response->assertRedirect(route('login'));
    }
}
