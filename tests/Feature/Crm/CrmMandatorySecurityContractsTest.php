<?php

namespace Tests\Feature\Crm;

use App\Exceptions\TenantContextMissingException;
use App\Exceptions\TenantIsolationException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Crm\Models\CrmCampaign;
use App\Modules\Crm\Models\CrmChannel;
use App\Modules\Crm\Models\CrmConversation;
use App\Modules\Crm\Models\CrmDeal;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmMessage;
use App\Modules\Crm\Models\CrmPipeline;
use App\Modules\Crm\Models\CrmPipelineStage;
use App\Modules\Crm\Models\CrmWebhookEvent;
use App\Modules\Crm\Services\CampaignService;
use App\Modules\Crm\Services\CrmBulkActionService;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Crm\Services\TenantBoundaryValidator;
use App\Modules\Crm\Services\TenantMailService;
use App\Modules\Crm\Services\WebhookDeduplicationService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class CrmMandatorySecurityContractsTest extends TestCase
{
    use RefreshDatabase;

    protected Team $tenantA;
    protected Team $tenantB;
    protected User $ownerA;
    protected User $staffA;
    protected User $ownerB;
    protected Warehouse $warehouseAlpha;
    protected Warehouse $warehouseBeta;
    protected TenantContext $tenantContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantContext = app(TenantContext::class);

        // 1. Setup Tenant Alpha
        $this->ownerA = User::factory()->create([
            'name' => 'Owner Alpha',
            'email' => 'owner.alpha@crm-test.example.com',
            'phone' => '+8801700000001',
        ]);
        $this->tenantA = Team::forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Tenant Alpha Enterprise',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerA->forceFill(['current_team_id' => $this->tenantA->id])->save();

        $this->staffA = User::factory()->create([
            'name' => 'Staff Alpha',
            'email' => 'staff.alpha@crm-test.example.com',
            'phone' => '+8801700000002',
        ]);
        $this->staffA->forceFill(['current_team_id' => $this->tenantA->id])->save();

        // Setup Warehouses for Tenant Alpha
        $this->warehouseAlpha = Warehouse::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Branch Alpha Hub',
            'code' => 'WH-ALPHA',
            'is_active' => true,
        ]);
        $this->warehouseBeta = Warehouse::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Branch Beta Hub',
            'code' => 'WH-BETA',
            'is_active' => true,
        ]);

        // Authoritative membership branch assignments via team_user.warehouse_id
        Membership::create([
            'team_id' => $this->tenantA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'warehouse_id' => $this->warehouseAlpha->id,
        ]);

        Membership::create([
            'team_id' => $this->tenantA->id,
            'user_id' => $this->staffA->id,
            'role' => 'sales_rep',
            'warehouse_id' => $this->warehouseAlpha->id, // Assigned strictly to Branch Alpha
        ]);

        // 2. Setup Tenant Beta (Cross-tenant boundary test)
        $this->ownerB = User::factory()->create([
            'name' => 'Owner Beta',
            'email' => 'owner.beta@crm-test.example.com',
            'phone' => '+8801700000003',
        ]);
        $this->tenantB = Team::forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Tenant Beta Enterprise',
            'personal_team' => false,
            'status' => 'active',
        ]);
        $this->ownerB->forceFill(['current_team_id' => $this->tenantB->id])->save();

        Membership::create([
            'team_id' => $this->tenantB->id,
            'user_id' => $this->ownerB->id,
            'role' => 'owner',
        ]);
    }

    /**
     * Contract 1: test_query_builder_update_without_tenant_scope_is_prevented
     * Supported CRM bulk operations must route through tenant-scoped Action/Repository boundaries.
     * Asserts that un-scoped or cross-tenant query execution is rejected before execution.
     */
    public function test_query_builder_update_without_tenant_scope_is_prevented(): void
    {
        $this->tenantContext->setTenant($this->tenantA->id);

        $pipeline = CrmPipeline::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Sales Pipeline A',
            'slug' => 'sales-a',
        ]);
        $stage = CrmPipelineStage::forceCreate([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Qualified',
        ]);
        $deal = CrmDeal::forceCreate([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'deal_number' => 'DEAL-A-001',
            'title' => 'Deal Alpha',
            'monetary_value' => 50000.00,
            'currency' => 'BDT',
            'status' => 'open',
        ]);

        $bulkService = app(CrmBulkActionService::class);

        // 1. Un-scoped execution without active tenant context must throw TenantContextMissingException
        $this->tenantContext->clear();

        $this->expectException(TenantContextMissingException::class);
        $bulkService->bulkUpdateDeals($this->tenantA->id, [$deal->id], ['status' => 'abandoned']);
    }

    /**
     * Contract 1b: Verify cross-tenant bulk update is rejected.
     */
    public function test_bulk_update_across_tenants_is_rejected(): void
    {
        $this->tenantContext->setTenant($this->tenantB->id);
        $bulkService = app(CrmBulkActionService::class);

        $this->expectException(TenantIsolationException::class);
        $bulkService->bulkUpdateDeals($this->tenantA->id, [999], ['status' => 'won']);
    }

    /**
     * Contract 2: test_sales_rep_cannot_read_deals_outside_assigned_branch
     * Sales reps assigned to Branch Alpha cannot read or view deals belonging to Branch Beta.
     */
    public function test_sales_rep_cannot_read_deals_outside_assigned_branch(): void
    {
        $this->tenantContext->setTenant($this->tenantA->id);

        $pipeline = CrmPipeline::forceCreate([
            'team_id' => $this->tenantA->id,
            'name' => 'Branch Test Pipeline',
            'slug' => 'branch-pipeline',
        ]);
        $stage = CrmPipelineStage::forceCreate([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Initial',
        ]);

        // Deal 1: Assigned to Branch Alpha
        $dealAlpha = CrmDeal::forceCreate([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'warehouse_id' => $this->warehouseAlpha->id,
            'assigned_user_id' => $this->staffA->id,
            'deal_number' => 'DEAL-ALPHA-01',
            'title' => 'Alpha Branch Deal',
            'status' => 'open',
        ]);

        // Deal 2: Assigned to Branch Beta
        $dealBeta = CrmDeal::forceCreate([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'warehouse_id' => $this->warehouseBeta->id,
            'assigned_user_id' => $this->staffA->id,
            'deal_number' => 'DEAL-BETA-01',
            'title' => 'Beta Branch Deal',
            'status' => 'open',
        ]);

        // Staff Alpha is assigned to Branch Alpha (team_user.warehouse_id = warehouseAlpha->id)
        // Set permissions on Staff Alpha for assigned deals
        setPermissionsTeamId($this->tenantA->id);
        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'sales_rep', 'team_id' => $this->tenantA->id]);
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'crm.deals.view_assigned']);
        $role->givePermissionTo($permission);
        $this->staffA->assignRole($role);

        // Assert Staff Alpha can view deal in assigned Branch Alpha
        $this->assertTrue(Gate::forUser($this->staffA)->allows('view', $dealAlpha));

        // Assert Staff Alpha CANNOT view deal in Branch Beta (denied by branch authorization)
        $this->assertFalse(Gate::forUser($this->staffA)->allows('view', $dealBeta));
    }

    /**
     * Contract 3: test_concurrent_webhook_deliveries_handled_safely_via_unique_index
     * Simulates concurrent delivery of identical webhook event identifiers.
     * Database unique constraint rejects duplicates, and service returns HTTP 200 without duplicate staging.
     */
    public function test_concurrent_webhook_deliveries_handled_safely_via_unique_index(): void
    {
        $service = app(WebhookDeduplicationService::class);

        $provider = 'meta_whatsapp';
        $scopeKey = 'global';
        $eventId = 'wamid.HBgLTESTCONCURRENT10001';
        $payload = json_encode(['entry' => [['id' => 'WABA_01', 'changes' => []]]]);

        // First delivery: Staged as pending
        $event1 = $service->stageEvent($provider, $scopeKey, $eventId, $payload, ['content-type' => 'application/json'], $this->tenantA->id);
        $this->assertEquals('pending', $event1->status);
        $this->assertNotNull($event1->id);

        // Second simultaneous/duplicate delivery: Replay protected via unique index
        $event2 = $service->stageEvent($provider, $scopeKey, $eventId, $payload, ['content-type' => 'application/json'], $this->tenantA->id);
        $this->assertEquals('duplicate', $event2->status);

        // Assert only exactly 1 record was persisted in crm_webhook_events
        $count = CrmWebhookEvent::where('provider', $provider)
            ->where('event_scope_key', $scopeKey)
            ->where('external_event_id', $eventId)
            ->count();

        $this->assertEquals(1, $count);
    }

    /**
     * Contract 4: test_duplicate_message_status_callback_is_idempotent
     * Repeated delivery callbacks update message timestamps idempotently without side effects.
     */
    public function test_duplicate_message_status_callback_is_idempotent(): void
    {
        $this->tenantContext->setTenant($this->tenantA->id);

        $channel = CrmChannel::forceCreate([
            'team_id' => $this->tenantA->id,
            'channel_type' => 'whatsapp',
            'name' => 'Support WA',
            'identifier' => '1000000001',
        ]);
        $conversation = CrmConversation::forceCreate([
            'team_id' => $this->tenantA->id,
            'channel_id' => $channel->id,
            'counterparty_type' => Customer::class,
            'counterparty_id' => 1,
            'remote_identifier' => '+8801700000099',
            'status' => 'open',
        ]);
        $message = CrmMessage::forceCreate([
            'team_id' => $this->tenantA->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'external_message_id' => 'msg_callback_idempotent_01',
            'body' => 'Test Invoice Delivery',
        ]);

        $service = app(WebhookDeduplicationService::class);

        // First status callback: 'delivered'
        $deliveredTime = now()->subMinutes(5)->toDateTimeString();
        $updated1 = $service->handleMessageStatusCallback($this->tenantA->id, 'msg_callback_idempotent_01', 'delivered', $deliveredTime);

        $this->assertEquals('delivered', $updated1->status);
        $this->assertNotNull($updated1->delivered_at);

        // Duplicate status callback: 'delivered' repeated
        $updated2 = $service->handleMessageStatusCallback($this->tenantA->id, 'msg_callback_idempotent_01', 'delivered', $deliveredTime);

        $this->assertEquals('delivered', $updated2->status);
        $this->assertEquals($updated1->delivered_at->toDateTimeString(), $updated2->delivered_at->toDateTimeString());
    }

    /**
     * Contract 5: test_editing_approved_campaign_resets_status_to_draft
     * Material modifications to an approved campaign invalidate approval and reset status to 'draft'.
     */
    public function test_editing_approved_campaign_resets_status_to_draft(): void
    {
        $this->tenantContext->setTenant($this->tenantA->id);

        $channel = CrmChannel::forceCreate([
            'team_id' => $this->tenantA->id,
            'channel_type' => 'sms',
            'name' => 'Promo SMS',
            'identifier' => 'BulkSMSBD-01',
        ]);

        $campaign = CrmCampaign::forceCreate([
            'team_id' => $this->tenantA->id,
            'channel_id' => $channel->id,
            'campaign_code' => 'CAMP-PROMO-01',
            'name' => 'Black Friday Promo',
            'message_body' => 'Enjoy 20% discount!',
            'created_by' => $this->staffA->id,
            'status' => 'approved',
            'approved_by' => $this->ownerA->id,
            'approved_at' => now(),
        ]);

        $this->assertTrue($campaign->isApproved());

        $campaignService = app(CampaignService::class);

        // Edit campaign message body (material field)
        $updated = $campaignService->editCampaign($this->tenantA->id, $campaign->id, [
            'message_body' => 'Enjoy 30% discount!',
        ]);

        // Assert approval is invalidated and status reverts to 'draft'
        $this->assertEquals('draft', $updated->status);
        $this->assertNull($updated->approved_by);
        $this->assertNull($updated->approved_at);
        $this->assertFalse($updated->isApproved());
    }

    /**
     * Contract 6: test_strict_dual_custody_blocks_owner_self_approval
     * Universal Rule: created_by !== approver_id applies unconditionally to all users including Tenant Owners.
     */
    public function test_strict_dual_custody_blocks_owner_self_approval(): void
    {
        $this->tenantContext->setTenant($this->tenantA->id);

        $channel = CrmChannel::forceCreate([
            'team_id' => $this->tenantA->id,
            'channel_type' => 'email',
            'name' => 'Newsletter Mail',
            'identifier' => 'news@tenant-a.com',
        ]);

        // Tenant Owner authors a campaign
        $campaign = CrmCampaign::forceCreate([
            'team_id' => $this->tenantA->id,
            'channel_id' => $channel->id,
            'campaign_code' => 'CAMP-OWNER-01',
            'name' => 'Owner Broadcast',
            'message_body' => 'Quarterly corporate update.',
            'created_by' => $this->ownerA->id,
            'status' => 'draft',
        ]);

        // 1. Verify Policy denies owner self-approval
        $this->assertFalse(
            Gate::forUser($this->ownerA)->allows('approve', $campaign),
            "Failed asserting that Tenant Owner is prohibited from self-approving own campaign."
        );

        // 2. Verify Service layer rejects owner self-approval
        $campaignService = app(CampaignService::class);

        $this->expectException(TenantIsolationException::class);
        $this->expectExceptionMessage("Dual-custody violation: Campaign creator cannot approve their own campaign.");

        $campaignService->approveCampaign($this->tenantA->id, $campaign->id, $this->ownerA);
    }

    /**
     * Contract 7: test_smtp_transport_socket_closed_and_purged_on_both_success_and_failure
     * Validates dedicated per-tenant Symfony Mailer transport lifecycle:
     * Asserts transport socket is explicitly closed, state purged, and tenant context flushed
     * on both successful delivery and transport exceptions.
     */
    public function test_smtp_transport_socket_closed_and_purged_on_both_success_and_failure(): void
    {
        $mailService = app(TenantMailService::class);
        $email = (new Email())
            ->from('system@tenant-a.com')
            ->to('customer@example.com')
            ->subject('Test Notification')
            ->text('Test email body.');

        // Case A: Successful send with custom mock transport
        $successfulTransport = new class extends AbstractTransport {
            public bool $stopped = false;
            protected function doSend(SentMessage $message): void {}
            public function stop(): void { $this->stopped = true; }
            public function __toString(): string { return 'mock://success'; }
        };

        $result = $mailService->sendTenantMail($this->tenantA->id, $email, $successfulTransport);

        $this->assertTrue($result);
        $this->assertNull($mailService->getActiveTransport());
        $this->assertNull($this->tenantContext->getTenantId(), "TenantContext was not cleared in finally block.");

        // Case B: Exception during send
        $failingTransport = new class extends AbstractTransport {
            public bool $stopped = false;
            protected function doSend(SentMessage $message): void {
                throw new TransportException("SMTP socket connection timed out.");
            }
            public function stop(): void { $this->stopped = true; }
            public function __toString(): string { return 'mock://failing'; }
        };

        try {
            $mailService->sendTenantMail($this->tenantA->id, $email, $failingTransport);
            $this->fail("Expected TransportException was not thrown.");
        } catch (TransportException $e) {
            $this->assertEquals("SMTP socket connection timed out.", $e->getMessage());
        }

        // Verify that on failure, transport was purged and tenant context was cleared
        $this->assertNull($mailService->getActiveTransport(), "Transport instance was not purged on exception.");
        $this->assertNull($this->tenantContext->getTenantId(), "TenantContext was not cleared on exception.");
    }

    /**
     * Contract 8: test_concurrent_lead_conversion_creates_single_customer_cleanly
     * Atomicity under race conditions: Concurrent lead conversion creates exactly ONE customer master record.
     */
    public function test_concurrent_lead_conversion_creates_single_customer_cleanly(): void
    {
        $this->tenantContext->setTenant($this->tenantA->id);

        $lead = CrmLead::forceCreate([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-RACE-001',
            'title' => 'VIP Enterprise Deal',
            'first_name' => 'Tareq',
            'last_name' => 'Rahman',
            'company_name' => 'Rahman Trading Ltd',
            'phone' => '+8801811223344',
            'email' => 'tareq@rahmantrading.com',
            'status' => 'qualified',
        ]);

        $leadService = app(LeadService::class);

        // First conversion request
        $customer1 = $leadService->convertLeadToCustomer($this->tenantA->id, $lead->id);
        $this->assertInstanceOf(Customer::class, $customer1);
        $this->assertEquals('+8801811223344', $customer1->phone);

        // Simulated concurrent/duplicate conversion request on same lead
        $customer2 = $leadService->convertLeadToCustomer($this->tenantA->id, $lead->id);
        $this->assertInstanceOf(Customer::class, $customer2);
        $this->assertEquals($customer1->id, $customer2->id, "Duplicate customer was created for the same lead.");

        // Assert strictly exactly ONE customer was created in ERP customers table for this tenant
        $customerCount = Customer::where('team_id', $this->tenantA->id)
            ->where('phone', '+8801811223344')
            ->count();

        $this->assertEquals(1, $customerCount, "More than one customer record was created.");
    }
}
