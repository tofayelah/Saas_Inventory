<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\SubscriptionPayment;
use App\Models\Team;
use App\Models\User;
use App\Services\SecureStorageService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Tests\TestCase;

class StorageAndApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;
    protected User $staffA;
    protected User $ownerB;
    protected Team $tenantA;
    protected Team $tenantB;
    protected SecureStorageService $storageService;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');

        $this->storageService = app(SecureStorageService::class);

        // Reset Spatie cached permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1. Create Tenant A
        $this->ownerA = User::factory()->create([
            'name' => 'Owner Alpha',
            'email' => 'owner.alpha@test.com',
            'phone' => '01711000001',
        ]);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Tenant Alpha',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->forceFill(['current_team_id' => $this->tenantA->id])->save();

        // 2. Create Staff in Tenant A
        $this->staffA = User::factory()->create([
            'name' => 'Staff Alpha',
            'email' => 'staff.alpha@test.com',
            'phone' => '01711000002',
        ]);
        $this->tenantA->users()->attach($this->staffA, ['role' => 'staff']);
        $this->staffA->forceFill(['current_team_id' => $this->tenantA->id])->save();

        // 3. Create Tenant B
        $this->ownerB = User::factory()->create([
            'name' => 'Owner Beta',
            'email' => 'owner.beta@test.com',
            'phone' => '01711000003',
        ]);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Tenant Beta',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->forceFill(['current_team_id' => $this->tenantB->id])->save();

        // 4. Setup Spatie Roles & Permissions for Tenant A
        setPermissionsTeamId($this->tenantA->id);
        $ownerRoleA = Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantA->id]);
        $staffRoleA = Role::firstOrCreate(['name' => 'staff', 'team_id' => $this->tenantA->id]);

        $permManageProducts = Permission::firstOrCreate(['name' => 'manage-products', 'team_id' => $this->tenantA->id]);
        $permViewReports = Permission::firstOrCreate(['name' => 'view-reports', 'team_id' => $this->tenantA->id]);
        $permManageCustomers = Permission::firstOrCreate(['name' => 'manage-customers', 'team_id' => $this->tenantA->id]);
        $permManageOrders = Permission::firstOrCreate(['name' => 'manage-orders', 'team_id' => $this->tenantA->id]);

        $ownerRoleA->syncPermissions([$permManageProducts, $permViewReports, $permManageCustomers, $permManageOrders]);
        $staffRoleA->syncPermissions([$permViewReports]); // Staff has view-reports, but not manage-products

        $this->ownerA->assignRole($ownerRoleA);
        $this->staffA->assignRole($staffRoleA);

        // 5. Setup Spatie Roles for Tenant B
        setPermissionsTeamId($this->tenantB->id);
        $ownerRoleB = Role::firstOrCreate(['name' => 'owner', 'team_id' => $this->tenantB->id]);
        $this->ownerB->assignRole($ownerRoleB);
    }

    /**
     * TEST 1: Tenant file storage isolates files by tenant. Cross-tenant download is blocked.
     */
    public function test_tenant_file_storage_isolates_files_by_tenant(): void
    {
        $file = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');
        $storedPath = $this->storageService->storeTenantFile($this->tenantA->id, $file, 'contracts');

        $this->assertStringStartsWith("tenants/{$this->tenantA->id}/contracts/", $storedPath);
        Storage::disk('private')->assertExists($storedPath);

        // Owner A can download file
        $this->actingAs($this->ownerA);
        $responseA = $this->get(route('secure-files.download', ['path' => $storedPath]));
        $responseA->assertOk();
        $responseA->assertHeader('X-Content-Type-Options', 'nosniff');

        // Owner B attempting to download Tenant A's file is strictly rejected
        $this->actingAs($this->ownerB);
        $responseB = $this->get(route('secure-files.download', ['path' => $storedPath]));
        $responseB->assertStatus(403);
    }

    /**
     * TEST 2: Path traversal sequences (../) in storage downloads are rejected.
     */
    public function test_storage_path_traversal_is_blocked(): void
    {
        $this->actingAs($this->ownerA);

        // Traversal via secure-files download
        $traversalPath = "tenants/{$this->tenantA->id}/contracts/../../../../.env";
        $response = $this->get(route('secure-files.download', ['path' => $traversalPath]));
        $this->assertTrue(in_array($response->status(), [400, 403, 404]));

        // Traversal via public storage fallback
        $publicTraversal = '../../../../.env';
        $fallbackResponse = $this->get('/storage/' . $publicTraversal);
        $this->assertTrue(in_array($fallbackResponse->status(), [400, 403, 404]));
    }

    /**
     * TEST 3: Unauthenticated access to tenant storage folder via public fallback is forbidden.
     */
    public function test_unauthenticated_user_cannot_access_tenant_storage_via_fallback(): void
    {
        $response = $this->get('/storage/tenants/1/letterheads/logo.png');
        $this->assertEquals(403, $response->status());
    }

    /**
     * TEST 4: Dangerous executable extensions are strictly rejected on upload.
     */
    public function test_dangerous_file_extensions_are_rejected_on_upload(): void
    {
        $this->actingAs($this->ownerA);

        $maliciousFile = UploadedFile::fake()->create('exploit.php', 10, 'application/x-php');

        $response = $this->post(route('secure-files.upload'), [
            'file' => $maliciousFile,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['file']);
    }

    /**
     * TEST 5: Sanctum tokens are tenant-bound. Requests resolve and isolate the bound tenant.
     */
    public function test_sanctum_token_is_strictly_bound_to_tenant(): void
    {
        Product::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Product Alpha 1',
            'sku' => 'SKU-A1',
            'selling_price' => 100,
            'is_active' => true,
        ]);

        Product::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Product Beta 1',
            'sku' => 'SKU-B1',
            'selling_price' => 200,
            'is_active' => true,
        ]);

        $newAccessToken = $this->ownerA->createTenantToken($this->tenantA->id, 'Alpha API Key', ['*']);
        $plainToken = $newAccessToken->plainTextToken;

        $this->assertEquals($this->tenantA->id, $newAccessToken->accessToken->team_id);

        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson(route('api.v1.products.index'));

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Product Alpha 1']);
        $response->assertJsonMissing(['name' => 'Product Beta 1']);
    }

    /**
     * TEST 6: Client-supplied tenant ID spoofing (X-Tenant-ID / team_id) is blocked.
     */
    public function test_cross_tenant_token_spoofing_is_blocked(): void
    {
        $newAccessToken = $this->ownerA->createTenantToken($this->tenantA->id, 'Alpha API Key', ['*']);
        $plainToken = $newAccessToken->plainTextToken;

        $responseHeader = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->withHeader('X-Tenant-ID', (string) $this->tenantB->id)
            ->getJson(route('api.v1.products.index'));

        $responseHeader->assertStatus(403);
        $responseHeader->assertJsonFragment(['error' => 'CrossTenantAccessBlocked']);

        $responseQuery = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson(route('api.v1.products.index', ['team_id' => $this->tenantB->id]));

        $responseQuery->assertStatus(403);
        $responseQuery->assertJsonFragment(['error' => 'CrossTenantAccessBlocked']);
    }

    /**
     * TEST 7: Token cannot be issued for a tenant that user does not belong to.
     */
    public function test_token_cannot_be_issued_for_tenant_user_does_not_belong_to(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("User does not have membership in tenant");

        $this->ownerA->createTenantToken($this->tenantB->id, 'Rogue Token', ['*']);
    }

    /**
     * TEST 8: Token ability and server-side role permission enforcement.
     */
    public function test_token_ability_and_server_side_permission_enforcement(): void
    {
        // 1. Staff A with limited token ability 'products:read' only
        $readOnlyToken = $this->staffA->createTenantToken($this->tenantA->id, 'Read Only Token', ['products:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $readOnlyToken)
            ->getJson(route('api.v1.products.index'))
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer ' . $readOnlyToken)
            ->postJson(route('api.v1.products.store'), [
                'name' => 'New Product',
                'sku' => 'SKU-NEW',
                'selling_price' => 50,
            ])
            ->assertStatus(403)
            ->assertJsonFragment(['error' => 'TokenAbilityDenied']);

        // 2. Staff A with token ability '*' but lacking server-side permission 'manage-products'
        app('auth')->forgetGuards();
        $wildcardToken = $this->staffA->createTenantToken($this->tenantA->id, 'Wildcard Token', ['*'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $wildcardToken)
            ->postJson(route('api.v1.products.store'), [
                'name' => 'New Product',
                'sku' => 'SKU-NEW',
                'selling_price' => 50,
            ])
            ->assertStatus(403)
            ->assertJsonFragment(['error' => 'PermissionDenied']);
    }

    /**
     * TEST 9: Suspended tenant tokens are strictly rejected.
     */
    public function test_suspended_tenant_token_is_rejected(): void
    {
        $token = $this->ownerA->createTenantToken($this->tenantA->id, 'Active Token', ['*'])->plainTextToken;

        $this->tenantA->update(['status' => 'suspended']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson(route('api.v1.products.index'));

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'TenantSuspended']);
    }

    /**
     * TEST 10: SSLCommerz IPN webhook rejects amount mismatch.
     */
    public function test_webhook_rejects_amount_mismatch(): void
    {
        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro',
            'price_bdt' => 2000,
            'annual_price_bdt' => 20000,
            'max_products' => 1000,
            'max_warehouses' => 5,
            'max_users' => 10,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-TEST-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 2000.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        $response = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-TEST-001',
            'val_id' => 'VAL-001',
            'amount' => 100.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['message' => 'Payment amount mismatch']);

        $payment->refresh();
        $this->assertEquals('pending', $payment->status);
    }

    /**
     * TEST 11: Authoritative server-to-server validation approves payment (SEC-G3-REV-01).
     */
    public function test_authoritative_server_to_server_validation_approves_payment(): void
    {
        Config::set('services.sslcommerz.sandbox', false);
        Config::set('services.sslcommerz.store_password', 'secret_store_pass');

        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro-auth',
            'price_bdt' => 3000,
            'annual_price_bdt' => 30000,
            'max_products' => 1000,
            'max_warehouses' => 5,
            'max_users' => 10,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-AUTH-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 3000.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        // Mock official SSLCommerz Order Validation API responding with VALID
        Http::fake([
            '*/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'VALIDATED',
                'tran_id' => 'PAY-AUTH-001',
                'val_id' => 'VAL-AUTH-999',
                'amount' => '3000.00',
                'currency' => 'BDT',
            ], 200),
        ]);

        $verifyKey = 'amount,currency,status,tran_id,val_id';
        $hashString = 'amount=3000&currency=BDT&status=VALID&tran_id=PAY-AUTH-001&val_id=VAL-AUTH-999&store_passwd=' . md5('secret_store_pass');
        $validSign = md5($hashString);

        $response = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-AUTH-001',
            'val_id' => 'VAL-AUTH-999',
            'amount' => 3000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
            'verify_key' => $verifyKey,
            'verify_sign' => $validSign,
        ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'success']);

        $payment->refresh();
        $this->assertEquals('approved', $payment->status);
        $this->assertEquals('VAL-AUTH-999', $payment->trx_id);
    }

    /**
     * TEST 12: Remote validation returning non-VALID status rejects payment (SEC-G3-REV-01).
     */
    public function test_invalid_server_validation_response_rejects_payment(): void
    {
        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro-inv',
            'price_bdt' => 2000,
            'annual_price_bdt' => 20000,
            'max_products' => 1000,
            'max_warehouses' => 5,
            'max_users' => 10,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-INV-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 2000.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        // Mock Order Validation API returning INVALID_TRANSACTION
        Http::fake([
            '*/validator/api/validationserverAPI.php*' => Http::response([
                'status' => 'INVALID_TRANSACTION',
                'tran_id' => 'PAY-INV-001',
                'val_id' => 'VAL-INV-001',
                'amount' => '2000.00',
                'currency' => 'BDT',
            ], 200),
        ]);

        $response = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-INV-001',
            'val_id' => 'VAL-INV-001',
            'amount' => 2000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $response->assertStatus(422);

        $payment->refresh();
        $this->assertEquals('rejected', $payment->status);
    }

    /**
     * TEST 13: Gateway timeout or connection failure fails closed (SEC-G3-REV-01).
     */
    public function test_provider_service_timeout_or_failure_rejects_payment(): void
    {
        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro-timeout',
            'price_bdt' => 2000,
            'annual_price_bdt' => 20000,
            'max_products' => 1000,
            'max_warehouses' => 5,
            'max_users' => 10,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-TIMEOUT-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 2000.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        // Mock network timeout/connection exception
        Http::fake([
            '*/validator/api/validationserverAPI.php*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out after 10000ms');
            },
        ]);

        $response = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-TIMEOUT-001',
            'val_id' => 'VAL-TIMEOUT-001',
            'amount' => 2000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $response->assertStatus(422);

        $payment->refresh();
        $this->assertEquals('rejected', $payment->status);
    }

    /**
     * TEST 14: FAILED and CANCELLED statuses in sandbox are rejected (SEC-G3-REV-02).
     */
    public function test_failed_and_cancelled_status_in_sandbox_cannot_approve_payment(): void
    {
        Config::set('services.sslcommerz.sandbox', true);

        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro-sandbox-fail',
            'price_bdt' => 1500,
            'annual_price_bdt' => 15000,
            'max_products' => 1000,
            'max_warehouses' => 5,
            'max_users' => 10,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-FAIL-SANDBOX-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 1500.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        // Attempt IPN with status=FAILED
        $responseFailed = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-FAIL-SANDBOX-001',
            'val_id' => 'VAL-FAIL',
            'amount' => 1500.00,
            'currency' => 'BDT',
            'status' => 'FAILED',
        ]);

        $responseFailed->assertStatus(422);
        $payment->refresh();
        $this->assertEquals('rejected', $payment->status);

        // Reset payment to pending and attempt status=CANCELLED
        $payment->update(['status' => 'pending']);

        $responseCancelled = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-FAIL-SANDBOX-001',
            'val_id' => 'VAL-CANCEL',
            'amount' => 1500.00,
            'currency' => 'BDT',
            'status' => 'CANCELLED',
        ]);

        $responseCancelled->assertStatus(422);
        $payment->refresh();
        $this->assertEquals('rejected', $payment->status);
    }

    /**
     * TEST 15: Duplicate val_id across existing approved payments is rejected (SEC-G3-REV-01).
     */
    public function test_duplicate_val_id_across_approved_payments_is_rejected(): void
    {
        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro-dup',
            'price_bdt' => 2500,
            'annual_price_bdt' => 25000,
            'max_products' => 1000,
            'max_warehouses' => 5,
            'max_users' => 10,
            'is_active' => true,
        ]);

        // Existing approved payment using val_id VAL-ALREADY-USED
        SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-PREV-001',
            'team_id' => $this->tenantB->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 2500.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'trx_id' => 'VAL-ALREADY-USED',
            'status' => 'approved',
        ]);

        // New pending payment attempting to reuse the same val_id
        $paymentNew = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-NEW-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 2500.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        $response = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-NEW-001',
            'val_id' => 'VAL-ALREADY-USED',
            'amount' => 2500.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Transaction validation ID has already been utilized by another approved payment.']);

        $paymentNew->refresh();
        $this->assertEquals('rejected', $paymentNew->status);
    }

    /**
     * TEST 16: API File Download requires documents:read token ability (SEC-G3-REV-03).
     */
    public function test_missing_documents_read_ability_rejects_api_file_download(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');
        $storedPath = $this->storageService->storeTenantFile($this->tenantA->id, $file, 'documents');

        // Token issued with only 'products:read', lacking 'documents:read'
        $tokenNoDoc = $this->ownerA->createTenantToken($this->tenantA->id, 'Catalog Only Token', ['products:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenNoDoc)
            ->getJson(route('api.v1.files.download', ['path' => $storedPath]));

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'TokenAbilityDenied']);
    }

    /**
     * TEST 17: Authorized API File Download with documents:read ability succeeds (SEC-G3-REV-03).
     */
    public function test_authorized_api_file_download_with_documents_read_ability(): void
    {
        $file = UploadedFile::fake()->create('financial_summary.pdf', 100, 'application/pdf');
        $storedPath = $this->storageService->storeTenantFile($this->tenantA->id, $file, 'documents');

        // Token issued with 'documents:read'
        $tokenWithDoc = $this->ownerA->createTenantToken($this->tenantA->id, 'Document Token', ['documents:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $tokenWithDoc)
            ->get(route('api.v1.files.download', ['path' => $storedPath]));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * TEST 18: Tenant letterheads are stored in private disk and accessible to PDFs (SEC-G3-REV-04).
     */
    public function test_tenant_letterheads_stored_in_private_disk(): void
    {
        $this->actingAs($this->ownerA);

        $invoiceBg = UploadedFile::fake()->image('custom_letterhead.png', 800, 1100);

        $response = $this->post(route('settings.company.update.post'), [
            'name' => 'Tenant Alpha Hardened',
            'currency_symbol' => '৳',
            'currency_code' => 'BDT',
            'invoice_prefix' => 'INV-',
            'po_prefix' => 'PO-',
            'invoice_bg' => $invoiceBg,
        ]);

        $response->assertSessionHas('success');

        $this->tenantA->refresh();
        $storedPath = $this->tenantA->invoice_bg_path;

        $this->assertNotNull($storedPath);
        $this->assertStringStartsWith("tenants/{$this->tenantA->id}/letterheads/", $storedPath);

        // Verified on private disk, NOT public disk
        Storage::disk('private')->assertExists($storedPath);
        Storage::disk('public')->assertMissing($storedPath);

        // Absolute path resolution for PDF engine
        $absolutePath = $this->tenantA->getInvoiceBgAbsolutePath();
        $this->assertNotNull($absolutePath);
        $this->assertStringContainsString('private', $absolutePath);
    }

    /**
     * TEST 19: MIME type mismatch against extension is rejected on upload (SEC-G3-REV-05).
     */
    public function test_mime_type_mismatch_with_extension_is_rejected_on_upload(): void
    {
        // PHP script masked as .png
        $spoofedFile = UploadedFile::fake()->create('stealth.png', 50, 'application/x-php');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage("MIME type 'application/x-php' does not match the expected MIME types for extension 'png'.");

        $this->storageService->storeTenantFile($this->tenantA->id, $spoofedFile, 'documents');
    }

    /**
     * TEST 20: Unsupported file extension is rejected on upload (SEC-G3-REV-05).
     */
    public function test_unsupported_file_extension_is_rejected_on_upload(): void
    {
        $executableFile = UploadedFile::fake()->create('tool.exe', 100, 'application/x-msdownload');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage("Upload of 'exe' file types is not permitted.");

        $this->storageService->storeTenantFile($this->tenantA->id, $executableFile, 'documents');
    }
}
