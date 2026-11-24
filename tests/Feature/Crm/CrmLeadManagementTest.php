<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmLead;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmLeadManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;
    protected User $ownerA;
    protected User $staffA;
    protected User $repA;
    protected User $ownerB;
    protected Warehouse $warehouseAlpha;
    protected Warehouse $warehouseBeta;
    protected Warehouse $warehouseOtherTenant;
    protected TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantContext = app(TenantContext::class);

        // Setup Tenant A
        $this->ownerA = User::factory()->create([
            'name' => 'Owner Alpha',
            'email' => 'owner.alpha@crm-test.example.com',
            'phone' => '+8801700000001',
        ]);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Alpha Holdings',
            'personal_team' => false,
        ]);
        $this->ownerA->current_team_id = $this->tenantA->id;
        $this->ownerA->save();

        $this->warehouseAlpha = Warehouse::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Dhaka Central Hub',
            'code' => 'WH-DAC-01',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->warehouseBeta = Warehouse::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Chittagong Hub',
            'code' => 'WH-CTG-01',
            'is_active' => true,
            'is_default' => false,
        ]);

        // Branch-scoped staff member in Tenant A (assigned to Dhaka Central Hub)
        $this->staffA = User::factory()->create([
            'name' => 'Staff Alpha Dhaka',
            'email' => 'staff.dhaka@crm-test.example.com',
            'phone' => '+8801700000002',
        ]);
        $this->tenantA->users()->attach($this->staffA, ['role' => 'editor']);
        Membership::where('team_id', $this->tenantA->id)
            ->where('user_id', $this->staffA->id)
            ->update(['warehouse_id' => $this->warehouseAlpha->id]);

        $this->staffA->current_team_id = $this->tenantA->id;
        $this->staffA->save();

        // Rep member in Tenant A
        $this->repA = User::factory()->create([
            'name' => 'Rep Alpha',
            'email' => 'rep.alpha@crm-test.example.com',
            'phone' => '+8801700000003',
        ]);
        $this->tenantA->users()->attach($this->repA, ['role' => 'editor']);
        $this->repA->current_team_id = $this->tenantA->id;
        $this->repA->save();

        // Setup Tenant B
        $this->ownerB = User::factory()->create([
            'name' => 'Owner Beta',
            'email' => 'owner.beta@crm-test.example.com',
            'phone' => '+8801700000099',
        ]);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Beta Global',
            'personal_team' => false,
        ]);
        $this->ownerB->current_team_id = $this->tenantB->id;
        $this->ownerB->save();

        $this->warehouseOtherTenant = Warehouse::forceCreate([
            'team_id' => $this->tenantB->id,
            'name' => 'Beta Sylhet Hub',
            'code' => 'WH-SYL-99',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->tenantContext->setTenant($this->tenantA);
    }

    public function test_user_can_view_leads_index_scoped_to_active_tenant(): void
    {
        // Lead in Tenant A
        CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0001',
            'title' => 'Alpha Industrial Deal',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        // Lead in Tenant B
        CrmLead::forceCreate([
            'team_id' => $this->tenantB->id,
            'lead_number' => 'LEAD-202611-9999',
            'title' => 'Beta Secret Deal',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseOtherTenant->id,
        ]);

        $response = $this->actingAs($this->ownerA)->get(route('crm.leads.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Crm/Leads/Index')
            ->has('leads.data', 1)
            ->where('leads.data.0.lead_number', 'LEAD-202611-0001')
            ->where('kpis.total', 1)
        );
    }

    public function test_cross_tenant_lead_view_is_forbidden_or_not_found(): void
    {
        // Lead in Tenant B
        $leadB = CrmLead::forceCreate([
            'team_id' => $this->tenantB->id,
            'lead_number' => 'LEAD-202611-0042',
            'title' => 'Beta Protected Lead',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseOtherTenant->id,
        ]);

        // Owner A tries to view Lead B
        $response = $this->actingAs($this->ownerA)->get(route('crm.leads.show', $leadB->id));

        // Global BelongsToTenant scope causes ModelNotFoundException (404)
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    public function test_branch_scoping_enforced_via_team_user_warehouse_id(): void
    {
        // Grant crm.leads.view_branch to Staff A
        setPermissionsTeamId($this->tenantA->id);
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'branch_staff', 'team_id' => $this->tenantA->id]);
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'crm.leads.view_branch']);
        $role->givePermissionTo($permission);
        $this->staffA->assignRole($role);

        // Dhaka lead (warehouseAlpha)
        $dhakaLead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0101',
            'title' => 'Dhaka Local Inquiry',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        // Chittagong lead (warehouseBeta)
        $ctgLead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0102',
            'title' => 'Chittagong Port Inquiry',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseBeta->id,
        ]);

        // Staff A is assigned to warehouseAlpha (Dhaka)
        $response = $this->actingAs($this->staffA)->get(route('crm.leads.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Crm/Leads/Index')
            ->has('leads.data', 1)
            ->where('leads.data.0.lead_number', 'LEAD-202611-0101')
        );

        // Staff A tries to view Chittagong lead directly -> policy checks team_user.warehouse_id
        $showCtgResponse = $this->actingAs($this->staffA)->get(route('crm.leads.show', $ctgLead->id));
        $this->assertTrue(in_array($showCtgResponse->status(), [403, 404]));
    }

    public function test_lead_creation_generates_sequential_number_and_persists(): void
    {
        $payload = [
            'title' => 'Major Government Tender',
            'first_name' => 'Tariq',
            'last_name' => 'Rahman',
            'company_name' => 'Tech Corp BD',
            'email' => 'tariq@techcorp.example.com',
            'phone' => '+880 1819-123456',
            'source' => 'website',
            'score' => 85,
            'warehouse_id' => $this->warehouseAlpha->id,
            'assigned_user_id' => $this->repA->id,
        ];

        $response = $this->actingAs($this->ownerA)->post(route('crm.leads.store'), $payload);

        $response->assertRedirect();

        $lead = CrmLead::where('team_id', $this->tenantA->id)
            ->where('title', 'Major Government Tender')
            ->first();

        $this->assertNotNull($lead);
        $this->assertStringStartsWith('LEAD-', $lead->lead_number);
        $this->assertEquals('Tech Corp BD', $lead->company_name);
        $this->assertEquals('+8801819123456', $lead->phone); // Normalized
        $this->assertEquals('tariq@techcorp.example.com', $lead->email);
        $this->assertEquals(85, $lead->score);
        $this->assertEquals($this->warehouseAlpha->id, $lead->warehouse_id);
        $this->assertEquals($this->repA->id, $lead->assigned_user_id);
    }

    public function test_cross_tenant_warehouse_rejected_on_lead_creation(): void
    {
        $payload = [
            'title' => 'Sneaky Cross-Tenant Warehouse Lead',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseOtherTenant->id, // Belongs to Tenant B
        ];

        $response = $this->actingAs($this->ownerA)->post(route('crm.leads.store'), $payload);

        $response->assertSessionHasErrors('warehouse_id');
        $this->assertDatabaseMissing('crm_leads', [
            'team_id' => $this->tenantA->id,
            'title' => 'Sneaky Cross-Tenant Warehouse Lead',
        ]);
    }

    public function test_lead_status_progression_and_updates(): void
    {
        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0201',
            'title' => 'Prospect Status Test',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        // Update status to contacted
        $res1 = $this->actingAs($this->ownerA)->put(route('crm.leads.update', $lead->id), [
            'status' => 'contacted',
        ]);
        $res1->assertRedirect();
        $this->assertEquals('contacted', $lead->fresh()->status);

        // Update status to qualified
        $res2 = $this->actingAs($this->ownerA)->put(route('crm.leads.update', $lead->id), [
            'status' => 'qualified',
            'score' => 90,
        ]);
        $res2->assertRedirect();
        $this->assertEquals('qualified', $lead->fresh()->status);
        $this->assertEquals(90, $lead->fresh()->score);
    }

    public function test_duplicate_check_detects_matching_leads_and_customers_in_tenant(): void
    {
        // Existing Lead
        CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0301',
            'title' => 'Existing Alpha Lead',
            'email' => 'duplicate.test@crm.example.com',
            'phone' => '+8801755555555',
            'status' => 'new',
            'source' => 'manual',
        ]);

        // Existing Customer
        Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Existing Alpha Customer',
            'email' => 'customer.match@crm.example.com',
            'phone' => '+8801766666666',
            'is_active' => true,
        ]);

        // Check duplicate by phone
        $resPhone = $this->actingAs($this->ownerA)->getJson(route('crm.leads.check-duplicates', [
            'phone' => '+880 1755-555555',
        ]));
        $resPhone->assertOk();
        $this->assertTrue($resPhone->json('has_duplicates'));
        $this->assertCount(1, $resPhone->json('matched_leads'));

        // Check duplicate by customer email
        $resEmail = $this->actingAs($this->ownerA)->getJson(route('crm.leads.check-duplicates', [
            'email' => 'customer.match@crm.example.com',
        ]));
        $resEmail->assertOk();
        $this->assertTrue($resEmail->json('has_duplicates'));
        $this->assertCount(1, $resEmail->json('matched_customers'));
    }

    public function test_duplicate_check_ignores_other_tenants(): void
    {
        // Existing Lead in Tenant B
        CrmLead::forceCreate([
            'team_id' => $this->tenantB->id,
            'lead_number' => 'LEAD-202611-0999',
            'title' => 'Tenant B Private Lead',
            'email' => 'tenantb.secret@crm.example.com',
            'phone' => '+8801999999999',
            'status' => 'new',
            'source' => 'manual',
        ]);

        // Querying from Tenant A
        $response = $this->actingAs($this->ownerA)->getJson(route('crm.leads.check-duplicates', [
            'email' => 'tenantb.secret@crm.example.com',
            'phone' => '+8801999999999',
        ]));

        $response->assertOk();
        $this->assertFalse($response->json('has_duplicates'));
        $this->assertEmpty($response->json('matched_leads'));
        $this->assertEmpty($response->json('matched_customers'));
    }

    public function test_lead_activity_creation_and_completion_toggle(): void
    {
        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0401',
            'title' => 'Activity Test Lead',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        // Add a call activity
        $actPayload = [
            'activity_type' => 'call',
            'title' => 'Initial Discovery Call',
            'description' => 'Discussed software licensing requirements.',
            'assigned_user_id' => $this->repA->id,
        ];

        $resStore = $this->actingAs($this->ownerA)->post(route('crm.leads.activities.store', $lead->id), $actPayload);
        $resStore->assertRedirect();

        $activity = CrmActivity::where('team_id', $this->tenantA->id)
            ->where('related_type', 'lead')
            ->where('related_id', $lead->id)
            ->first();

        $this->assertNotNull($activity);
        $this->assertEquals('Initial Discovery Call', $activity->title);
        $this->assertFalse($activity->is_completed);

        // Toggle activity completion
        $resToggle = $this->actingAs($this->ownerA)->post(route('crm.leads.activities.toggle', [$lead->id, $activity->id]));
        $resToggle->assertRedirect();

        $this->assertTrue($activity->fresh()->is_completed);
        $this->assertNotNull($activity->fresh()->completed_at);
    }

    public function test_atomic_lead_conversion_to_canonical_customer(): void
    {
        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0501',
            'title' => 'Big Enterprise Conversion',
            'first_name' => 'Kamal',
            'last_name' => 'Hossain',
            'company_name' => 'Hossain Logistics Ltd',
            'email' => 'kamal@hossainlogistics.example.com',
            'phone' => '+8801711223344',
            'status' => 'qualified',
            'source' => 'referral',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        $convertPayload = [
            'name' => 'Kamal Hossain (Logistics Division)',
            'company_name' => 'Hossain Logistics Ltd',
            'phone' => '+8801711223344',
            'email' => 'kamal@hossainlogistics.example.com',
            'address' => 'Motijheel C/A, Dhaka',
        ];

        $res = $this->actingAs($this->ownerA)->post(route('crm.leads.convert', $lead->id), $convertPayload);
        $res->assertRedirect();

        $lead->refresh();
        $this->assertEquals('converted', $lead->status);
        $this->assertNotNull($lead->converted_at);
        $this->assertNotNull($lead->converted_customer_id);

        $customer = Customer::find($lead->converted_customer_id);
        $this->assertNotNull($customer);
        $this->assertEquals($this->tenantA->id, $customer->team_id);
        $this->assertEquals('Kamal Hossain (Logistics Division)', $customer->name);
        $this->assertEquals('Hossain Logistics Ltd', $customer->company_name);
        $this->assertEquals('+8801711223344', $customer->phone);
        $this->assertEquals('kamal@hossainlogistics.example.com', $customer->email);
        $this->assertEquals('Motijheel C/A, Dhaka', $customer->address);
    }

    public function test_lead_conversion_links_existing_matching_customer(): void
    {
        // Existing canonical Customer
        $existingCustomer = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Existing Apex Retailer',
            'phone' => '+8801700998877',
            'email' => 'orders@apexretail.example.com',
            'is_active' => true,
        ]);

        // Lead with same phone
        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0502',
            'title' => 'Apex New Branch Inquiry',
            'company_name' => 'Apex Retail',
            'phone' => '+8801700998877',
            'status' => 'qualified',
            'source' => 'website',
        ]);

        $res = $this->actingAs($this->ownerA)->post(route('crm.leads.convert', $lead->id), []);
        $res->assertRedirect();

        $lead->refresh();
        $this->assertEquals('converted', $lead->status);
        $this->assertEquals($existingCustomer->id, $lead->converted_customer_id);

        // Ensure no second duplicate customer was created
        $customerCount = Customer::where('team_id', $this->tenantA->id)
            ->where('phone', '+8801700998877')
            ->count();
        $this->assertEquals(1, $customerCount);
    }

    public function test_lead_deletion_authorization_and_removal(): void
    {
        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0601',
            'title' => 'Spam Lead to Delete',
            'status' => 'unqualified',
            'source' => 'website',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        $res = $this->actingAs($this->ownerA)->delete(route('crm.leads.destroy', $lead->id));
        $res->assertRedirect(route('crm.leads.index'));

        $this->assertDatabaseMissing('crm_leads', [
            'id' => $lead->id,
        ]);
    }

    public function test_sales_rep_with_view_assigned_only_sees_assigned_leads(): void
    {
        setPermissionsTeamId($this->tenantA->id);
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'sales_rep', 'team_id' => $this->tenantA->id]);
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'crm.leads.view_assigned']);
        $role->givePermissionTo($permission);
        $this->repA->assignRole($role);

        // Lead assigned to repA
        CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0701',
            'title' => 'Rep Assigned Lead',
            'status' => 'new',
            'source' => 'manual',
            'assigned_user_id' => $this->repA->id,
        ]);

        // Lead assigned to another user (ownerA)
        CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0702',
            'title' => 'Owner Assigned Lead',
            'status' => 'new',
            'source' => 'manual',
            'assigned_user_id' => $this->ownerA->id,
        ]);

        $response = $this->actingAs($this->repA)->get(route('crm.leads.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Crm/Leads/Index')
            ->has('leads.data', 1)
            ->where('leads.data.0.lead_number', 'LEAD-202611-0701')
        );
    }

    public function test_lead_conversion_does_not_touch_clients_table(): void
    {
        $initialClientsCount = \Illuminate\Support\Facades\DB::table('clients')->count();

        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0801',
            'title' => 'Client Table Isolation Check',
            'first_name' => 'Rafiq',
            'last_name' => 'Ahmed',
            'company_name' => 'Ahmed Builders',
            'phone' => '+8801722334455',
            'status' => 'qualified',
            'source' => 'referral',
        ]);

        $res = $this->actingAs($this->ownerA)->post(route('crm.leads.convert', $lead->id), [
            'name' => 'Rafiq Ahmed',
        ]);
        $res->assertRedirect();

        $afterClientsCount = \Illuminate\Support\Facades\DB::table('clients')->count();

        // Canonical customer was created, but clients (Project Costing) remains 100% untouched
        $this->assertEquals($initialClientsCount, $afterClientsCount);
        $this->assertDatabaseHas('customers', [
            'team_id' => $this->tenantA->id,
            'name' => 'Rafiq Ahmed',
        ]);
    }

    public function test_tenant_owner_cannot_delete_converted_lead(): void
    {
        $customer = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Protected Converted Customer',
            'is_active' => true,
        ]);

        $convertedLead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0901',
            'title' => 'Protected Converted Lead',
            'status' => 'converted',
            'converted_customer_id' => $customer->id,
            'converted_at' => now(),
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        // Attempt deletion as Tenant Owner
        $response = $this->actingAs($this->ownerA)->delete(route('crm.leads.destroy', $convertedLead->id));

        // Policy / Controller blocks with 403 Forbidden or 422 Unprocessable
        $this->assertTrue(in_array($response->status(), [403, 422]));

        // Assert lead and customer remain untouched
        $this->assertDatabaseHas('crm_leads', ['id' => $convertedLead->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_user_with_crm_leads_delete_permission_cannot_delete_converted_lead(): void
    {
        setPermissionsTeamId($this->tenantA->id);
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'lead_admin', 'team_id' => $this->tenantA->id]);
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'crm.leads.delete']);
        $role->givePermissionTo($permission);
        $this->staffA->assignRole($role);

        $customer = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Valued Converted Client',
            'is_active' => true,
        ]);

        $convertedLead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0902',
            'title' => 'Enterprise Converted Lead',
            'status' => 'converted',
            'converted_customer_id' => $customer->id,
            'converted_at' => now(),
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        $response = $this->actingAs($this->staffA)->delete(route('crm.leads.destroy', $convertedLead->id));

        $this->assertTrue(in_array($response->status(), [403, 422]));
        $this->assertDatabaseHas('crm_leads', ['id' => $convertedLead->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_direct_controller_deletion_request_on_converted_lead_is_rejected(): void
    {
        $customer = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Direct Controller Customer',
            'is_active' => true,
        ]);

        $convertedLead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0903',
            'title' => 'Direct Controller Lead',
            'status' => 'converted',
            'converted_customer_id' => $customer->id,
            'converted_at' => now(),
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        $response = $this->actingAs($this->ownerA)->delete(route('crm.leads.destroy', $convertedLead->id));

        $this->assertTrue(in_array($response->status(), [403, 422]));
        $this->assertDatabaseHas('crm_leads', ['id' => $convertedLead->id]);
    }

    public function test_direct_service_deletion_invocation_on_converted_lead_throws_exception(): void
    {
        $leadService = app(\App\Modules\Crm\Services\LeadService::class);

        $customer = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Service Protection Customer',
            'is_active' => true,
        ]);

        $convertedLead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0904',
            'title' => 'Service Protection Lead',
            'status' => 'converted',
            'converted_customer_id' => $customer->id,
            'converted_at' => now(),
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be deleted as it is permanently linked to canonical customer/');

        $leadService->deleteLead($this->tenantA->id, $convertedLead);

        // Verify lead is not deleted
        $this->assertDatabaseHas('crm_leads', ['id' => $convertedLead->id]);
    }

    public function test_unauthorized_user_cannot_delete_unconverted_lead(): void
    {
        // repA has no crm.leads.delete permission and is not owner
        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0905',
            'title' => 'Active Unconverted Lead',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        $response = $this->actingAs($this->repA)->delete(route('crm.leads.destroy', $lead->id));

        $this->assertEquals(403, $response->status());
        $this->assertDatabaseHas('crm_leads', ['id' => $lead->id]);
    }

    public function test_cross_tenant_deletion_remains_blocked(): void
    {
        // Lead in Tenant B
        $leadB = CrmLead::forceCreate([
            'team_id' => $this->tenantB->id,
            'lead_number' => 'LEAD-202611-0906',
            'title' => 'Tenant B Lead to Attempt Delete',
            'status' => 'new',
            'source' => 'manual',
            'warehouse_id' => $this->warehouseOtherTenant->id,
        ]);

        // Owner of Tenant A attempts delete
        $response = $this->actingAs($this->ownerA)->delete(route('crm.leads.destroy', $leadB->id));

        $this->assertTrue(in_array($response->status(), [403, 404]));
        $this->assertDatabaseHas('crm_leads', ['id' => $leadB->id, 'team_id' => $this->tenantB->id]);
    }

    public function test_rejected_deletion_leaves_lead_and_canonical_customer_unchanged(): void
    {
        $customer = Customer::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Untouched Canonical Customer',
            'phone' => '+8801799887766',
            'email' => 'untouched@example.com',
            'is_active' => true,
        ]);

        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-202611-0907',
            'title' => 'Untouched Converted Lead',
            'status' => 'converted',
            'converted_customer_id' => $customer->id,
            'converted_at' => now()->subDays(5),
            'source' => 'website',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        $origConvertedAt = $lead->converted_at->toDateTimeString();

        $response = $this->actingAs($this->ownerA)->delete(route('crm.leads.destroy', $lead->id));
        $this->assertTrue(in_array($response->status(), [403, 422]));

        $lead->refresh();
        $this->assertEquals('converted', $lead->status);
        $this->assertEquals($customer->id, $lead->converted_customer_id);
        $this->assertEquals($origConvertedAt, $lead->converted_at->toDateTimeString());

        $customer->refresh();
        $this->assertEquals('Untouched Canonical Customer', $customer->name);
        $this->assertEquals('+8801799887766', $customer->phone);
    }
}
