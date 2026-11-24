<?php

namespace Tests\Feature\Crm;

use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantIsolationException;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Crm\Models\CrmChannel;
use App\Modules\Crm\Models\CrmDeal;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmPipeline;
use App\Modules\Crm\Models\CrmPipelineStage;
use App\Modules\Crm\Services\TenantBoundaryValidator;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;
    protected User $ownerA;
    protected User $ownerB;
    protected Warehouse $warehouseA;
    protected Warehouse $warehouseB;
    protected Customer $customerA;
    protected Customer $customerB;
    protected Product $productA;
    protected Product $productB;
    protected TenantContext $tenantContext;
    protected TenantBoundaryValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantContext = app(TenantContext::class);
        $this->validator = app(TenantBoundaryValidator::class);

        // 1. Setup Tenant A
        $this->ownerA = User::factory()->create([
            'name' => 'Owner Alpha',
            'email' => 'owner.alpha@tenant-a.com',
            'phone' => '+8801711111111',
        ]);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Tenant Alpha Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->forceFill(['current_team_id' => $this->tenantA->id])->save();

        $this->warehouseA = Warehouse::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Warehouse',
            'code' => 'WH-A',
            'is_active' => true,
        ]);

        $this->customerA = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Customer Alpha',
            'email' => 'customer@alpha.com',
            'phone' => '+8801811111111',
            'is_active' => true,
        ]);

        $catA = ProductCategory::forceCreate(['team_id' => $this->tenantA->id, 'name' => 'Hardware A']);
        $brandA = Brand::forceCreate(['team_id' => $this->tenantA->id, 'name' => 'Brand A']);
        $this->productA = Product::forceCreate([
            'team_id' => $this->tenantA->id,
            'category_id' => $catA->id,
            'brand_id' => $brandA->id,
            'name' => 'Product Alpha',
            'sku' => 'SKU-A-01',
            'cost_price' => 100.00,
            'unit_price' => 150.00,
            'reorder_level' => 5,
        ]);

        // 2. Setup Tenant B
        $this->ownerB = User::factory()->create([
            'name' => 'Owner Beta',
            'email' => 'owner.beta@tenant-b.com',
            'phone' => '+8801722222222',
        ]);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Tenant Beta Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->forceFill(['current_team_id' => $this->tenantB->id])->save();

        $this->warehouseB = Warehouse::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Beta Warehouse',
            'code' => 'WH-B',
            'is_active' => true,
        ]);

        $this->customerB = Customer::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Customer Beta',
            'email' => 'customer@beta.com',
            'phone' => '+8801822222222',
            'is_active' => true,
        ]);

        $catB = ProductCategory::forceCreate(['team_id' => $this->tenantB->id, 'name' => 'Hardware B']);
        $brandB = Brand::forceCreate(['team_id' => $this->tenantB->id, 'name' => 'Brand B']);
        $this->productB = Product::forceCreate([
            'team_id' => $this->tenantB->id,
            'category_id' => $catB->id,
            'brand_id' => $brandB->id,
            'name' => 'Product Beta',
            'sku' => 'SKU-B-01',
            'cost_price' => 200.00,
            'unit_price' => 280.00,
            'reorder_level' => 5,
        ]);
    }

    /**
     * Test Tier 1 Composite Foreign Key: crm_leads referencing foreign crm_channels fails.
     */
    public function test_crm_lead_rejects_cross_tenant_channel_via_composite_fk(): void
    {
        $channelB = CrmChannel::forceCreate([
            'team_id' => $this->tenantB->id,
            'channel_type' => 'email',
            'name' => 'Beta Email Channel',
            'identifier' => 'sales@beta.com',
        ]);

        $this->expectException(QueryException::class);

        // Attempt to create Lead in Tenant A referencing Channel of Tenant B
        DB::table('crm_leads')->insert([
            'team_id' => $this->tenantA->id,
            'channel_id' => $channelB->id,
            'lead_number' => 'LEAD-ATTACK-01',
            'title' => 'Malicious Lead',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test Tier 1 Composite Foreign Key: crm_pipeline_stages referencing foreign crm_pipelines fails.
     */
    public function test_crm_pipeline_stage_rejects_cross_tenant_pipeline_via_composite_fk(): void
    {
        $pipelineB = CrmPipeline::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Beta Sales Pipeline',
            'slug' => 'beta-sales-pipe',
            'is_default' => true,
        ]);

        $this->expectException(QueryException::class);

        // Attempt to create Stage in Tenant A referencing Pipeline of Tenant B
        DB::table('crm_pipeline_stages')->insert([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipelineB->id,
            'name' => 'Injected Stage',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test Tier 1 Composite Foreign Key: crm_deals referencing foreign crm_pipelines fails.
     */
    public function test_crm_deal_rejects_cross_tenant_pipeline_via_composite_fk(): void
    {
        $pipelineB = CrmPipeline::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Beta Sales Pipeline 2',
            'slug' => 'beta-sales-pipe-2',
            'is_default' => true,
        ]);
        $stageB = CrmPipelineStage::forceCreate([
            'team_id' => $this->tenantB->id,
            'pipeline_id' => $pipelineB->id,
            'name' => 'Stage Beta 1',
            'sort_order' => 1,
        ]);

        $this->expectException(QueryException::class);

        // Attempt to create Deal in Tenant A referencing Pipeline and Stage of Tenant B
        DB::table('crm_deals')->insert([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipelineB->id,
            'stage_id' => $stageB->id,
            'deal_number' => 'DEAL-ATTACK-01',
            'title' => 'Cross-Tenant Deal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test Tier 1 Composite Foreign Key: crm_deal_items referencing foreign crm_deals fails.
     */
    public function test_crm_deal_item_rejects_cross_tenant_deal_via_composite_fk(): void
    {
        $pipelineA = CrmPipeline::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Sales Pipeline',
            'slug' => 'alpha-sales-pipe',
            'is_default' => true,
        ]);
        $stageA = CrmPipelineStage::forceCreate([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipelineA->id,
            'name' => 'Stage Alpha 1',
            'sort_order' => 1,
        ]);
        $dealA = CrmDeal::forceCreate([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipelineA->id,
            'stage_id' => $stageA->id,
            'deal_number' => 'DEAL-A-01',
            'title' => 'Alpha Deal',
        ]);

        $this->expectException(QueryException::class);

        // Attempt to create Deal Item in Tenant B referencing Deal of Tenant A
        DB::table('crm_deal_items')->insert([
            'team_id' => $this->tenantB->id,
            'deal_id' => $dealA->id,
            'name' => 'Cross-Tenant Item',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test Tier 2 Validator: Validates legacy Customer belongs to active tenant.
     */
    public function test_tenant_boundary_validator_blocks_cross_tenant_customer(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Referenced Customer [ID: ' . $this->customerB->id . '] does not belong to the active tenant.');

        $this->validator->validateCustomer($this->tenantA->id, $this->customerB->id);
    }

    /**
     * Test Tier 2 Validator: Validates legacy Warehouse belongs to active tenant.
     */
    public function test_tenant_boundary_validator_blocks_cross_tenant_warehouse(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Referenced Warehouse [ID: ' . $this->warehouseB->id . '] does not belong to the active tenant or is inactive.');

        $this->validator->validateWarehouse($this->tenantA->id, $this->warehouseB->id);
    }

    /**
     * Test Tier 2 Validator: Validates legacy Product belongs to active tenant.
     */
    public function test_tenant_boundary_validator_blocks_cross_tenant_product(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Referenced Product [ID: ' . $this->productB->id . '] does not belong to the active tenant.');

        $this->validator->validateProduct($this->tenantA->id, $this->productB->id);
    }

    /**
     * Test Tier 2 Validator: Validates Quotation belongs to active tenant.
     */
    public function test_tenant_boundary_validator_blocks_cross_tenant_quotation(): void
    {
        $quotationB = Quotation::forceCreate([
            'team_id' => $this->tenantB->id,
            'quotation_number' => 'QT-BETA-001',
            'quotation_date' => now()->toDateString(),
            'customer_id' => $this->customerB->id,
            'status' => 'draft',
            'total_amount' => 5000.00,
        ]);

        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Referenced Quotation [ID: ' . $quotationB->id . '] does not belong to the active tenant.');

        $this->validator->validateQuotation($this->tenantA->id, $quotationB->id);
    }

    /**
     * Test Polymorphic Validation: Disallows non-whitelisted model classes.
     */
    public function test_tenant_boundary_validator_blocks_non_whitelisted_polymorphic_type(): void
    {
        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage('Polymorphic target type [App\Models\Team] is not an allowlisted CRM entity.');

        $this->validator->validatePolymorphicTarget(
            $this->tenantA->id,
            Team::class,
            $this->tenantA->id
        );
    }

    /**
     * Test Global Tenant Scope: Fail-closed when no tenant is bound.
     */
    public function test_crm_models_fail_closed_when_tenant_context_missing(): void
    {
        // Explicitly clear tenant context
        $this->tenantContext->setTenant(null);

        $this->expectException(TenantContextMissingException::class);

        // Attempting to query CrmLead without tenant context must fail closed
        CrmLead::all();
    }

    /**
     * Test Global Tenant Scope: Queries only return current tenant records.
     */
    public function test_crm_models_return_only_active_tenant_records(): void
    {
        $this->tenantContext->setTenant($this->tenantA->id);

        CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-A-100',
            'title' => 'Alpha Prospect',
        ]);

        $this->tenantContext->setTenant($this->tenantB->id);

        CrmLead::forceCreate([
            'team_id' => $this->tenantB->id,
            'lead_number' => 'LEAD-B-100',
            'title' => 'Beta Prospect',
        ]);

        // When scoped to Tenant A:
        $this->tenantContext->setTenant($this->tenantA->id);
        $leadsA = CrmLead::all();
        $this->assertCount(1, $leadsA);
        $this->assertEquals('LEAD-A-100', $leadsA->first()->lead_number);

        // When scoped to Tenant B:
        $this->tenantContext->setTenant($this->tenantB->id);
        $leadsB = CrmLead::all();
        $this->assertCount(1, $leadsB);
        $this->assertEquals('LEAD-B-100', $leadsB->first()->lead_number);
    }
}
