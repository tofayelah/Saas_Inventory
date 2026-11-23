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
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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
        $staffRoleA->syncPermissions([$permViewReports]); // Staff can view reports/products, but cannot manage/write products

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
        // Unauthenticated request to /storage/tenants/1/...
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

        $response->assertStatus(302); // Validation error redirect
        $response->assertSessionHasErrors(['file']);
    }

    /**
     * TEST 5: Sanctum tokens are tenant-bound. Requests resolve and isolate the bound tenant.
     */
    public function test_sanctum_token_is_strictly_bound_to_tenant(): void
    {
        // Create products in Tenant A and Tenant B
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

        // Issue token for Tenant A
        $newAccessToken = $this->ownerA->createTenantToken($this->tenantA->id, 'Alpha API Key', ['*']);
        $plainToken = $newAccessToken->plainTextToken;

        // Verify token in DB has team_id set
        $this->assertEquals($this->tenantA->id, $newAccessToken->accessToken->team_id);

        // Make API request with Bearer token
        $response = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->getJson(route('api.v1.products.index'));

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Product Alpha 1']);
        $response->assertJsonMissing(['name' => 'Product Beta 1']); // Tenant B product strictly invisible
    }

    /**
     * TEST 6: Client-supplied tenant ID spoofing (X-Tenant-ID / team_id) is blocked.
     */
    public function test_cross_tenant_token_spoofing_is_blocked(): void
    {
        $newAccessToken = $this->ownerA->createTenantToken($this->tenantA->id, 'Alpha API Key', ['*']);
        $plainToken = $newAccessToken->plainTextToken;

        // Try to access Tenant B by supplying X-Tenant-ID header
        $responseHeader = $this->withHeader('Authorization', 'Bearer ' . $plainToken)
            ->withHeader('X-Tenant-ID', (string) $this->tenantB->id)
            ->getJson(route('api.v1.products.index'));

        $responseHeader->assertStatus(403);
        $responseHeader->assertJsonFragment(['error' => 'CrossTenantAccessBlocked']);

        // Try to access Tenant B by supplying team_id query parameter
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

        // Owner A tries to create a token for Tenant B
        $this->ownerA->createTenantToken($this->tenantB->id, 'Rogue Token', ['*']);
    }

    /**
     * TEST 8: Token ability and server-side role permission enforcement.
     */
    public function test_token_ability_and_server_side_permission_enforcement(): void
    {
        // 1. Staff A with limited token ability 'products:read' only
        $readOnlyToken = $this->staffA->createTenantToken($this->tenantA->id, 'Read Only Token', ['products:read'])->plainTextToken;

        // Reading products succeeds
        $this->withHeader('Authorization', 'Bearer ' . $readOnlyToken)
            ->getJson(route('api.v1.products.index'))
            ->assertOk();

        // Storing product fails due to missing token ability 'products:write'
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

        // Suspend Tenant A
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

        // Attacker attempts to validate payment with amount 100 instead of 2000
        $response = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-TEST-001',
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
     * TEST 11: Webhook signature verification in production mode.
     */
    public function test_webhook_signature_verification_in_production(): void
    {
        Config::set('services.sslcommerz.sandbox', false);
        Config::set('services.sslcommerz.store_password', 'secret_store_pass');

        $plan = Plan::forceCreate([
            'name' => 'Pro Plan',
            'slug' => 'pro-sig',
            'price_bdt' => 3000,
            'annual_price_bdt' => 30000,
            'max_products' => 1000,
            'max_warehouses' => 5,
            'max_users' => 10,
            'is_active' => true,
        ]);

        $payment = SubscriptionPayment::forceCreate([
            'payment_number' => 'PAY-SIG-001',
            'team_id' => $this->tenantA->id,
            'plan_id' => $plan->id,
            'amount_bdt' => 3000.00,
            'payment_method' => 'sslcommerz',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
        ]);

        // 1. Missing signature in production mode -> 403
        $responseNoSig = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-SIG-001',
            'amount' => 3000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
        ]);
        $responseNoSig->assertStatus(403);

        // 2. Valid calculated signature in production mode -> 200
        $verifyKey = 'amount,currency,status,tran_id';
        $hashString = 'amount=3000&currency=BDT&status=VALID&tran_id=PAY-SIG-001&store_passwd=' . md5('secret_store_pass');
        $validSign = md5($hashString);

        $responseWithSig = $this->postJson(route('sslcommerz.ipn'), [
            'tran_id' => 'PAY-SIG-001',
            'amount' => 3000.00,
            'currency' => 'BDT',
            'status' => 'VALID',
            'verify_key' => $verifyKey,
            'verify_sign' => $validSign,
        ]);

        $responseWithSig->assertOk();
        $responseWithSig->assertJsonFragment(['status' => 'success']);

        $payment->refresh();
        $this->assertEquals('approved', $payment->status);
    }
}
