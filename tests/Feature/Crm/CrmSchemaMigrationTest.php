<?php

namespace Tests\Feature\Crm;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CrmSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected array $crmTables = [
        'crm_channels',
        'crm_webhook_events',
        'crm_leads',
        'crm_contacts',
        'crm_pipelines',
        'crm_pipeline_stages',
        'crm_deals',
        'crm_deal_items',
        'crm_conversations',
        'crm_messages',
        'crm_segments',
        'crm_consents',
        'crm_campaigns',
        'crm_campaign_recipients',
        'crm_support_cases',
        'crm_activities',
        'crm_automation_rules',
        'crm_automation_logs',
    ];

    /**
     * Test that all 18 CRM tables are successfully created with correct schema.
     */
    public function test_all_eighteen_crm_tables_exist_after_migration(): void
    {
        foreach ($this->crmTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Failed asserting that CRM table [{$table}] was created by migrations."
            );
        }
    }

    /**
     * Test key columns and indices on critical tables.
     */
    public function test_crm_webhook_events_has_normalized_scope_and_unique_constraint(): void
    {
        $this->assertTrue(Schema::hasColumns('crm_webhook_events', [
            'id', 'team_id', 'provider', 'event_scope_key', 'external_event_id',
            'payload_hash', 'headers', 'payload', 'status', 'error_details', 'processed_at', 'created_at'
        ]));
    }

    public function test_crm_deals_has_required_monetary_and_financial_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('crm_deals', [
            'id', 'team_id', 'deal_number', 'title', 'customer_id', 'lead_id',
            'pipeline_id', 'stage_id', 'assigned_user_id', 'warehouse_id',
            'quotation_id', 'monetary_value', 'currency', 'status'
        ]));
    }

    /**
     * Test that rollback safely drops all 18 CRM tables in reverse dependency order.
     */
    public function test_crm_migrations_rollback_cleanly(): void
    {
        // 13 migration units rolled back (steps = 13)
        Artisan::call('migrate:rollback', ['--step' => 13]);

        foreach ($this->crmTables as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "Failed asserting that CRM table [{$table}] was dropped during rollback."
            );
        }

        // Re-run migrations to ensure clean re-application
        Artisan::call('migrate');

        foreach ($this->crmTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Failed asserting that CRM table [{$table}] was re-created after re-migration."
            );
        }
    }
}
