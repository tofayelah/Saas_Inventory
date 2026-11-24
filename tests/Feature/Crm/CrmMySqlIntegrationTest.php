<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use App\Modules\Crm\Models\CrmCampaign;
use App\Modules\Crm\Models\CrmChannel;
use App\Modules\Crm\Models\CrmConsent;
use App\Modules\Crm\Models\CrmConversation;
use App\Modules\Crm\Models\CrmDeal;
use App\Modules\Crm\Models\CrmDealItem;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmMessage;
use App\Modules\Crm\Models\CrmPipeline;
use App\Modules\Crm\Models\CrmPipelineStage;
use App\Modules\Crm\Models\CrmWebhookEvent;
use App\Modules\Crm\Services\LeadService;
use App\Modules\Crm\Services\WebhookDeduplicationService;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class CrmMySqlIntegrationTest extends TestCase
{
    protected string $mysqlConnection = 'mysql_test_crm';
    protected Team $tenantA;
    protected Team $tenantB;
    protected User $ownerA;
    protected User $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure dedicated MySQL connection to isolated test database
        Config::set("database.connections.{$this->mysqlConnection}", [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'inomanage_crm_test_isolated',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);

        DB::setDefaultConnection($this->mysqlConnection);

        // Ensure clean state in MySQL isolated database
        $this->cleanDatabase();

        // Seed basic tenants for foreign key verification
        $this->ownerA = User::on($this->mysqlConnection)->create([
            'name' => 'Owner Alpha',
            'email' => 'owner.alpha@crm-mysql.test',
            'phone' => '+8801700000001',
            'password' => bcrypt('secret'),
        ]);

        $this->tenantA = Team::on($this->mysqlConnection)->forceCreate([
            'user_id' => $this->ownerA->id,
            'name' => 'Tenant Alpha Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);

        $this->ownerB = User::on($this->mysqlConnection)->create([
            'name' => 'Owner Beta',
            'email' => 'owner.beta@crm-mysql.test',
            'phone' => '+8801700000002',
            'password' => bcrypt('secret'),
        ]);

        $this->tenantB = Team::on($this->mysqlConnection)->forceCreate([
            'user_id' => $this->ownerB->id,
            'name' => 'Tenant Beta Corp',
            'personal_team' => false,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    protected function cleanDatabase(): void
    {
        $db = DB::connection($this->mysqlConnection);
        $db->statement('SET FOREIGN_KEY_CHECKS = 0;');
        $tables = [
            'crm_automation_logs',
            'crm_automation_rules',
            'crm_activities',
            'crm_support_cases',
            'crm_campaign_recipients',
            'crm_campaigns',
            'crm_consents',
            'crm_segments',
            'crm_messages',
            'crm_conversations',
            'crm_deal_items',
            'crm_deals',
            'crm_pipeline_stages',
            'crm_pipelines',
            'crm_contacts',
            'crm_leads',
            'crm_webhook_events',
            'crm_channels',
            'customers',
            'teams',
            'users',
        ];
        foreach ($tables as $table) {
            $db->statement("TRUNCATE TABLE {$table};");
        }
        $db->statement('SET FOREIGN_KEY_CHECKS = 1;');
    }

    /**
     * Requirement 1: Verify exact MySQL version and InnoDB engines.
     */
    public function test_mysql_target_engine_version_and_table_engines(): void
    {
        $version = DB::connection($this->mysqlConnection)->selectOne('SELECT VERSION() as ver')->ver;
        $this->assertStringStartsWith('8.4.', $version, "Target database must be MySQL 8.4.x (actual: {$version})");

        $tables = DB::connection($this->mysqlConnection)->select("
            SELECT TABLE_NAME, ENGINE, TABLE_COLLATION 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = 'inomanage_crm_test_isolated' 
              AND TABLE_NAME LIKE 'crm_%'
        ");

        $this->assertCount(18, $tables, 'All 18 CRM tables must be present in MySQL.');

        foreach ($tables as $t) {
            $this->assertEquals('InnoDB', $t->ENGINE, "Table {$t->TABLE_NAME} must use InnoDB engine.");
            $this->assertEquals('utf8mb4_0900_ai_ci', $t->TABLE_COLLATION, "Table {$t->TABLE_NAME} must use utf8mb4 collation.");
        }
    }

    /**
     * Requirement 1.7: Test Tier 1 Composite Foreign Key cross-tenant injection rejection at MySQL engine level.
     */
    public function test_mysql_rejects_cross_tenant_composite_foreign_key_insert(): void
    {
        $db = DB::connection($this->mysqlConnection);

        // Create Pipeline in Tenant B
        $pipelineBId = $db->table('crm_pipelines')->insertGetId([
            'team_id' => $this->tenantB->id,
            'name' => 'Beta Sales Pipeline',
            'slug' => 'beta-pipeline',
            'is_default' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Attempt to insert Stage in Tenant A pointing to Pipeline in Tenant B
        // MySQL InnoDB must reject this with SQLSTATE 23000 / Error 1452 foreign key constraint fails
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('1452'); // MySQL Error 1452: Cannot add or update a child row: a foreign key constraint fails

        $db->table('crm_pipeline_stages')->insert([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipelineBId,
            'name' => 'Malicious Injected Stage',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Requirement 1.7: Test cross-tenant Deal Item injection into foreign Deal is rejected by MySQL.
     */
    public function test_mysql_rejects_cross_tenant_deal_item_via_composite_fk(): void
    {
        $db = DB::connection($this->mysqlConnection);

        $pipeAId = $db->table('crm_pipelines')->insertGetId([
            'team_id' => $this->tenantA->id,
            'name' => 'Alpha Pipeline',
            'slug' => 'alpha-pipe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stageAId = $db->table('crm_pipeline_stages')->insertGetId([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipeAId,
            'name' => 'Stage Alpha 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dealAId = $db->table('crm_deals')->insertGetId([
            'team_id' => $this->tenantA->id,
            'pipeline_id' => $pipeAId,
            'stage_id' => $stageAId,
            'deal_number' => 'DEAL-A-100',
            'title' => 'Alpha Deal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Malicious insert: Tenant B attempts to attach deal item to Tenant A's deal
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('1452');

        $db->table('crm_deal_items')->insert([
            'team_id' => $this->tenantB->id,
            'deal_id' => $dealAId,
            'description' => 'Injected Cross-Tenant Line Item',
            'quantity' => 1,
            'unit_price' => 250,
            'subtotal' => 250,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Requirement 3: Consent Uniqueness Audit.
     * Verifies that separate consent purposes can be recorded independently,
     * and duplicate consent for the same purpose is rejected by MySQL unique constraint.
     */
    public function test_consent_uniqueness_supports_multiple_purposes_and_blocks_duplicates(): void
    {
        $db = DB::connection($this->mysqlConnection);
        $destination = '+8801811223344';

        // 1. Record marketing consent for recipient
        $db->table('crm_consents')->insert([
            'team_id' => $this->tenantA->id,
            'channel_type' => 'whatsapp',
            'destination' => $destination,
            'purpose' => 'marketing',
            'status' => 'opted_in',
            'source' => 'web_form',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Record transactional consent for the exact same recipient (must succeed independently)
        $db->table('crm_consents')->insert([
            'team_id' => $this->tenantA->id,
            'channel_type' => 'whatsapp',
            'destination' => $destination,
            'purpose' => 'transactional',
            'status' => 'opted_in',
            'source' => 'pos_checkout',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Assert both distinct purposes exist for the destination
        $count = $db->table('crm_consents')
            ->where('team_id', $this->tenantA->id)
            ->where('destination', $destination)
            ->count();
        $this->assertEquals(2, $count, 'Recipient must hold independent consent records per purpose.');

        // 3. Attempt duplicate consent for the same purpose ('marketing')
        // MySQL must reject with Error 1062 Duplicate entry
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('1062');

        $db->table('crm_consents')->insert([
            'team_id' => $this->tenantA->id,
            'channel_type' => 'whatsapp',
            'destination' => $destination,
            'purpose' => 'marketing',
            'status' => 'opted_in',
            'source' => 'duplicate_attempt',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Requirement 1.9: Test concurrent webhook insertions using independent database connections on MySQL.
     */
    public function test_concurrent_webhook_insertions_using_independent_mysql_connections(): void
    {
        $provider = 'meta_whatsapp';
        $scopeKey = 'global';
        $eventId = 'wamid.HBgLTESTCONCURRENT_MYSQL_' . uniqid();
        $payload = json_encode(['test' => 'concurrent_payload']);
        $payloadHash = hash('sha256', $payload);

        // Open two independent raw PDO connections to MySQL
        $pdo1 = new PDO('mysql:host=127.0.0.1;port=3306;dbname=inomanage_crm_test_isolated;charset=utf8mb4', 'root', '');
        $pdo1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo2 = new PDO('mysql:host=127.0.0.1;port=3306;dbname=inomanage_crm_test_isolated;charset=utf8mb4', 'root', '');
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Connection 1 inserts event
        $stmt1 = $pdo1->prepare("
            INSERT INTO crm_webhook_events 
            (team_id, provider, event_scope_key, external_event_id, payload_hash, payload, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt1->execute([$this->tenantA->id, $provider, $scopeKey, $eventId, $payloadHash, $payload]);
        $firstInsertId = (int) $pdo1->lastInsertId();
        $this->assertGreaterThan(0, $firstInsertId);

        // Connection 2 attempts simultaneous duplicate insert
        $stmt2 = $pdo2->prepare("
            INSERT INTO crm_webhook_events 
            (team_id, provider, event_scope_key, external_event_id, payload_hash, payload, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");

        $duplicateCaught = false;
        try {
            $stmt2->execute([$this->tenantA->id, $provider, $scopeKey, $eventId, $payloadHash, $payload]);
        } catch (\PDOException $e) {
            // MySQL error code 1062: Duplicate entry for key 'uq_crm_webhook_events'
            if (str_contains($e->getMessage(), '1062')) {
                $duplicateCaught = true;
            }
        }

        $this->assertTrue($duplicateCaught, 'Concurrent duplicate insert on independent connection must be rejected by MySQL unique index.');

        // Verify only 1 record exists in MySQL
        $countStmt = $pdo1->prepare("SELECT COUNT(*) as c FROM crm_webhook_events WHERE external_event_id = ?");
        $countStmt->execute([$eventId]);
        $row = $countStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(1, (int) $row['c']);
    }

    /**
     * Requirement 4: Unknown-Tenant Webhook Ingress Audit.
     * Verifies that events without a resolvable team_id are safely persisted with team_id=NULL,
     * protected from tenant isolation leaks, and idempotent.
     */
    public function test_unknown_tenant_webhook_ingress_safely_persisted_and_isolated(): void
    {
        $service = app(WebhookDeduplicationService::class);

        $provider = 'meta_whatsapp';
        $scopeKey = 'global';
        $eventId = 'wamid.HBgLUNKNOWN_TENANT_' . uniqid();
        $payload = json_encode(['entry' => [['changes' => ['value' => ['messages' => []]]]]]);

        // Ingress with unknown tenant ($teamId = null)
        $event = $service->stageEvent(
            provider: $provider,
            eventScopeKey: $scopeKey,
            externalEventId: $eventId,
            payload: $payload,
            headers: ['x-hub-signature-256' => 'sha256=test_sig'],
            teamId: null
        );

        $this->assertNull($event->team_id, 'Unmatched event must have null team_id.');
        $this->assertEquals('pending', $event->status);
        $this->assertNotNull($event->id);

        // Verify in database: persisted with team_id null
        $db = DB::connection($this->mysqlConnection);
        $persisted = $db->table('crm_webhook_events')->where('id', $event->id)->first();
        $this->assertNotNull($persisted);
        $this->assertNull($persisted->team_id);

        // Verify replay defense on unknown-tenant event
        $duplicate = $service->stageEvent(
            provider: $provider,
            eventScopeKey: $scopeKey,
            externalEventId: $eventId,
            payload: $payload,
            headers: ['x-hub-signature-256' => 'sha256=test_sig'],
            teamId: null
        );
        $this->assertEquals('duplicate', $duplicate->status);
        $this->assertEquals($event->id, $duplicate->id);

        // Verify total row count remains exactly 1
        $count = $db->table('crm_webhook_events')
            ->where('external_event_id', $eventId)
            ->count();
        $this->assertEquals(1, $count);
    }

    /**
     * Requirement 1.10: Test lead conversion locking and uniqueness using MySQL.
     */
    public function test_mysql_lead_conversion_locking_and_customer_creation(): void
    {
        app(TenantContext::class)->setTenant($this->tenantA->id);
        $db = DB::connection($this->mysqlConnection);

        // Insert lead in MySQL
        $leadId = $db->table('crm_leads')->insertGetId([
            'team_id' => $this->tenantA->id,
            'lead_number' => 'LEAD-MYSQL-001',
            'title' => 'MySQL Conversion Lead',
            'first_name' => 'Zubair',
            'last_name' => 'Ahmed',
            'company_name' => 'Ahmed Trade Link',
            'phone' => '+8801822334455',
            'email' => 'zubair@ahmedtrade.com',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var CrmLead $lead */
        $lead = CrmLead::on($this->mysqlConnection)->find($leadId);

        $service = app(LeadService::class);
        $customer = $service->convertLeadToCustomer($this->tenantA->id, $lead->id);

        $this->assertNotNull($customer->id);
        $this->assertEquals($this->tenantA->id, $customer->team_id);
        $this->assertEquals('Zubair Ahmed', $customer->name);
        $this->assertEquals('Ahmed Trade Link', $customer->company_name);
        $this->assertTrue((bool) $customer->is_active);

        // Verify lead is marked converted in MySQL
        $lead->refresh();
        $this->assertEquals('converted', $lead->status);
        $this->assertEquals($customer->id, $lead->converted_customer_id);

        // Attempting to convert again must return the already-converted customer without duplicates
        $secondCustomer = $service->convertLeadToCustomer($this->tenantA->id, $lead->id);
        $this->assertEquals($customer->id, $secondCustomer->id);

        $customerCount = Customer::on($this->mysqlConnection)
            ->where('team_id', $this->tenantA->id)
            ->where('phone', '+8801822334455')
            ->count();
        $this->assertEquals(1, $customerCount, 'Exactly one customer record must exist in MySQL.');
    }
}
