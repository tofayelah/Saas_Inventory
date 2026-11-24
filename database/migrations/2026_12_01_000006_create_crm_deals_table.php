<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('deal_number', 50);
            $table->string('title', 191);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->unsignedBigInteger('pipeline_id');
            $table->unsignedBigInteger('stage_id');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->decimal('monetary_value', 15, 2)->default(0.00);
            $table->string('currency', 3)->default('BDT');
            $table->date('expected_close_date')->nullable();
            $table->date('actual_close_date')->nullable();
            $table->enum('status', ['open', 'won', 'lost', 'abandoned'])->default('open');
            $table->string('loss_reason', 191)->nullable();
            $table->timestamps();

            // Composite parent unique index for Tier 1 composite foreign keys
            $table->unique(['team_id', 'id'], 'crm_deals_team_id_id_unique');

            // Business uniqueness & query optimization indexes
            $table->unique(['team_id', 'deal_number'], 'crm_deals_team_deal_number_unique');
            $table->index(['team_id', 'customer_id'], 'crm_deals_team_customer_index');
            $table->index(['team_id', 'stage_id'], 'crm_deals_team_stage_index');
            $table->index(['team_id', 'assigned_user_id'], 'crm_deals_team_assigned_user_index');
            $table->index(['team_id', 'warehouse_id'], 'crm_deals_team_warehouse_index');
            $table->index(['team_id', 'status'], 'crm_deals_team_status_index');

            // Tier 1 Composite Foreign Keys
            $table->foreign(['team_id', 'pipeline_id'], 'fk_crm_deals_pipeline')
                ->references(['team_id', 'id'])
                ->on('crm_pipelines')
                ->restrictOnDelete();

            $table->foreign(['team_id', 'stage_id'], 'fk_crm_deals_stage')
                ->references(['team_id', 'id'])
                ->on('crm_pipeline_stages')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_deals');
    }
};
