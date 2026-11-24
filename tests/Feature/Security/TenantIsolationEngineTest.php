<?php

namespace Tests\Feature\Security;

use App\Contracts\TenantAwareJob;
use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantIsolationException;
use App\Jobs\Middleware\TenantAwareJobMiddleware;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SubscriptionPayment;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationEngineTest extends TestCase
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

        // Setup Tenant A Warehouse within Tenant A context
        $this->warehouseA = app(TenantContext::class)->executeAsTenant($this->tenantA, function () {
            return Warehouse::create([
                'name' => 'Acme Main Hub',
                'code' => 'ACM-01',
                'is_active' => true,
                'is_default' => true,
            ]);
        });

        // 2. Setup Tenant B
        $this->ownerB = User::factory()->create(['name' => 'Owner B', 'email' => 'owner.b@example.com']);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Beta Logistics',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->forceFill(['current_team_id' => $this->tenantB->id])->save();
        RolesAndPermissionsSeeder::seedRolesForTeam($this->tenantB->id);
        setPermissionsTeamId($this->tenantB->id);
        $this->ownerB->assignRole('owner');

        // Setup Tenant B Warehouse within Tenant B context
        $this->warehouseB = app(TenantContext::class)->executeAsTenant($this->tenantB, function () {
            return Warehouse::create([
                'name' => 'Beta Distribution Hub',
                'code' => 'BET-01',
                'is_active' => true,
                'is_default' => true,
            ]);
        });
    }

    /**
     * Test 1: Tenant A cannot read Tenant B's records (Product & Customer).
     */
    public function test_tenant_a_cannot_read_tenant_b_records(): void
    {
        $productB = app(TenantContext::class)->executeAsTenant($this->tenantB, function () {
            return Product::create([
                'name' => 'Confidential Server B',
                'sku' => 'SRV-BET-001',
                'unit' => 'pcs',
                'cost_price' => 50000,
                'selling_price' => 65000,
                'reorder_level' => 2,
                'reorder_quantity' => 5,
                'is_active' => true,
            ]);
        });

        $customerB = app(TenantContext::class)->executeAsTenant($this->tenantB, function () {
            return Customer::create([
                'name' => 'Secret Client B',
                'email' => 'client@betalogistics.com',
                'phone' => '01700000002',
                'is_active' => true,
            ]);
        });

        // 1. Authenticate as Tenant A Owner and attempt HTTP read on Tenant B Product
        $responseProduct = $this->actingAs($this->ownerA)->get(route('products.edit', $productB->id));
        $this->assertTrue(in_array($responseProduct->status(), [403, 404]), "Expected 403 or 404, got {$responseProduct->status()}");

        // 2. Direct Eloquent Query in Tenant A context should not return Tenant B records
        $queryResult = app(TenantContext::class)->executeAsTenant($this->tenantA, function () use ($productB, $customerB) {
            return [
                'product' => Product::find($productB->id),
                'customer' => Customer::find($customerB->id),
            ];
        });

        $this->assertNull($queryResult['product'], 'Tenant A query unexpectedly retrieved Tenant B product.');
        $this->assertNull($queryResult['customer'], 'Tenant A query unexpectedly retrieved Tenant B customer.');
    }

    /**
     * Test 2: Tenant A cannot update Tenant B's records.
     */
    public function test_tenant_a_cannot_update_tenant_b_records(): void
    {
        $productB = app(TenantContext::class)->executeAsTenant($this->tenantB, function () {
            return Product::create([
                'name' => 'Original B Product',
                'sku' => 'ORIG-B-001',
                'unit' => 'pcs',
                'cost_price' => 1000,
                'selling_price' => 1500,
                'reorder_level' => 5,
                'reorder_quantity' => 10,
                'is_active' => true,
            ]);
        });

        // Tenant A attempts to update Tenant B's product via HTTP PUT
        $response = $this->actingAs($this->ownerA)->put(route('products.update', $productB->id), [
            'name' => 'Compromised Name',
            'sku' => 'ORIG-B-001',
            'unit' => 'pcs',
            'cost_price' => 1000,
            'selling_price' => 1500,
            'reorder_level' => 5,
            'reorder_quantity' => 10,
        ]);

        $this->assertTrue(in_array($response->status(), [403, 404]), "Expected 403 or 404, got {$response->status()}");

        // Ensure database state was NOT altered
        $freshProduct = app(TenantContext::class)->executeAsTenant($this->tenantB, fn () => Product::find($productB->id));
        $this->assertEquals('Original B Product', $freshProduct->name);
    }

    /**
     * Test 3: Tenant A cannot delete Tenant B's records.
     */
    public function test_tenant_a_cannot_delete_tenant_b_records(): void
    {
        $productB = app(TenantContext::class)->executeAsTenant($this->tenantB, function () {
            return Product::create([
                'name' => 'Protected B Product',
                'sku' => 'PROT-B-001',
                'unit' => 'pcs',
                'cost_price' => 200,
                'selling_price' => 300,
                'reorder_level' => 1,
                'reorder_quantity' => 5,
                'is_active' => true,
            ]);
        });

        // Tenant A attempts DELETE on Tenant B's product
        $response = $this->actingAs($this->ownerA)->delete(route('products.destroy', $productB->id));
        $this->assertTrue(in_array($response->status(), [403, 404]), "Expected 403 or 404, got {$response->status()}");

        // Verify product remains undeleted in Tenant B
        $existsInB = app(TenantContext::class)->executeAsTenant($this->tenantB, fn () => Product::where('id', $productB->id)->exists());
        $this->assertTrue($existsInB);
    }

    /**
     * Test 4: Cross-tenant foreign keys are rejected (referencing foreign warehouse).
     */
    public function test_cross_tenant_foreign_keys_are_rejected(): void
    {
        // Tenant A tries to create a product referencing Tenant B's warehouse
        $response = $this->actingAs($this->ownerA)->post(route('products.store'), [
            'name' => 'Cross-Tenant Test Product',
            'sku' => 'XTEN-001',
            'unit' => 'pcs',
            'cost_price' => 100,
            'selling_price' => 150,
            'reorder_level' => 2,
            'reorder_quantity' => 5,
            'initial_warehouse_id' => $this->warehouseB->id, // Tenant B Warehouse!
            'initial_stock_quantity' => 10,
        ]);

        // Validation rule Rule::exists('warehouses', 'id')->where('team_id', $teamId) must fail
        $response->assertSessionHasErrors('initial_warehouse_id');
        $this->assertDatabaseMissing('products', ['sku' => 'XTEN-001']);
    }

    /**
     * Test 5: Client-supplied team_id is strictly overwritten by server-side context.
     */
    public function test_client_supplied_team_id_is_strictly_overwritten(): void
    {
        // Tenant A sends POST with malicious "team_id" => Tenant B ID
        $response = $this->actingAs($this->ownerA)->post(route('products.store'), [
            'team_id' => $this->tenantB->id, // Attempted IDOR injection
            'name' => 'Injected Product',
            'sku' => 'INJ-001',
            'unit' => 'pcs',
            'cost_price' => 100,
            'selling_price' => 200,
            'reorder_level' => 1,
            'reorder_quantity' => 5,
        ]);

        $response->assertRedirect(route('products.index'));

        // The product MUST belong to Tenant A, NOT Tenant B
        $product = Product::withoutGlobalScopes()->where('sku', 'INJ-001')->first();
        $this->assertNotNull($product);
        $this->assertEquals($this->tenantA->id, $product->team_id);
        $this->assertNotEquals($this->tenantB->id, $product->team_id);
    }

    /**
     * Test 6: Mutating team_id on existing record is strictly rejected with TenantIsolationException.
     */
    public function test_mutating_team_id_on_existing_model_throws_exception(): void
    {
        $productA = app(TenantContext::class)->executeAsTenant($this->tenantA, function () {
            return Product::create([
                'name' => 'Fixed Tenant Product',
                'sku' => 'FIX-001',
                'unit' => 'pcs',
                'cost_price' => 50,
                'selling_price' => 80,
                'reorder_level' => 1,
                'reorder_quantity' => 5,
            ]);
        });

        $this->expectException(TenantIsolationException::class);

        app(TenantContext::class)->executeAsTenant($this->tenantA, function () use ($productA) {
            $productA->team_id = $this->tenantB->id;
            $productA->save();
        });
    }

    /**
     * Test 7: Missing tenant context in CLI / background execution fails closed.
     */
    public function test_missing_tenant_context_fails_closed(): void
    {
        // Clear all tenant context
        app(TenantContext::class)->clear();

        // In console / testing environment, executing a query on a TenantAware model without tenant context throws
        $this->expectException(TenantContextMissingException::class);

        Product::count();
    }

    /**
     * Test 8: Queue middleware restores and clears tenant context during background job execution.
     */
    public function test_queue_job_execution_restores_and_cleans_up_tenant_context(): void
    {
        // Clear ambient context
        app(TenantContext::class)->clear();
        $this->assertFalse(app(TenantContext::class)->hasTenant());

        // Create a dummy tenant-aware job
        $dummyJob = new class($this->tenantA->id) implements TenantAwareJob {
            public function __construct(public int $teamId) {}
            public function getTenantId(): int { return $this->teamId; }
        };

        $middleware = new TenantAwareJobMiddleware();

        $executedInsideTenantId = null;

        $middleware->handle($dummyJob, function () use (&$executedInsideTenantId) {
            // Verify context is restored inside the job execution
            $executedInsideTenantId = app(TenantContext::class)->getTenantId();
            $this->assertTrue(app(TenantContext::class)->hasTenant());
        });

        // Verify that the job executed inside Tenant A
        $this->assertEquals($this->tenantA->id, $executedInsideTenantId);

        // Verify that after job execution, context was automatically cleared (no leak in worker pool)
        $this->assertFalse(app(TenantContext::class)->hasTenant());
    }

    /**
     * Test 9: Privileged mode is explicitly controlled and restores state.
     */
    public function test_privileged_mode_is_explicit_and_cleans_up(): void
    {
        app(TenantContext::class)->clear();
        $this->assertFalse(app(TenantContext::class)->isPrivileged());

        // In privileged mode, queries across all tenants succeed
        $totalCount = app(TenantContext::class)->executePrivileged(function () {
            $this->assertTrue(app(TenantContext::class)->isPrivileged());
            return Team::count();
        });

        $this->assertGreaterThanOrEqual(2, $totalCount);

        // Outside privileged block, privileged state is restored to false
        $this->assertFalse(app(TenantContext::class)->isPrivileged());
    }

    /**
     * Test 10: Subscription payments are strictly partitioned between tenants.
     */
    public function test_subscription_payments_are_isolated_between_tenants(): void
    {
        $plan = \App\Models\Plan::create([
            'name' => 'Starter Plan',
            'slug' => 'starter-plan-test',
            'price_bdt' => 999.00,
            'annual_price_bdt' => 9990.00,
            'max_products' => 500,
            'max_warehouses' => 2,
            'max_users' => 5,
            'max_quotations' => 100,
            'max_sales_orders' => 100,
            'max_purchase_orders' => 100,
            'max_projects' => 10,
            'max_employees' => 10,
            'is_active' => true,
        ]);

        $paymentB = app(TenantContext::class)->executeAsTenant($this->tenantB, function () use ($plan) {
            return SubscriptionPayment::create([
                'payment_number' => 'PAY-BET-999',
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'amount_bdt' => 999.00,
                'payment_method' => 'bkash',
                'sender_number' => '01711223344',
                'trx_id' => 'TRXBET99999',
                'status' => 'pending',
            ]);
        });

        // Tenant A cannot query Tenant B's payment
        $retrieved = app(TenantContext::class)->executeAsTenant($this->tenantA, function () use ($paymentB) {
            return SubscriptionPayment::find($paymentB->id);
        });

        $this->assertNull($retrieved, 'Tenant A query unexpectedly retrieved Tenant B subscription payment.');

        // Tenant B can query their own payment
        $retrievedB = app(TenantContext::class)->executeAsTenant($this->tenantB, function () use ($paymentB) {
            return SubscriptionPayment::find($paymentB->id);
        });

        $this->assertNotNull($retrievedB);
        $this->assertEquals('PAY-BET-999', $retrievedB->payment_number);
    }
}
