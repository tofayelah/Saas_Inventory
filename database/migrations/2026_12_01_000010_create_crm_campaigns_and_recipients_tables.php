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
        Schema::create('crm_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('campaign_code', 50);
            $table->string('name', 150);
            $table->unsignedBigInteger('channel_id');
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->string('template_id', 100)->nullable();
            $table->text('message_body')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'dispatching', 'completed', 'paused', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();

            // Composite parent unique index for Tier 1 composite foreign keys
            $table->unique(['team_id', 'id'], 'crm_campaigns_team_id_id_unique');

            // Business uniqueness & query optimization indexes
            $table->unique(['team_id', 'campaign_code'], 'crm_campaigns_team_campaign_code_unique');
            $table->index(['team_id', 'status'], 'crm_campaigns_team_status_index');

            // Tier 1 Composite Foreign Keys
            $table->foreign(['team_id', 'channel_id'], 'fk_crm_campaigns_channel')
                ->references(['team_id', 'id'])
                ->on('crm_channels')
                ->restrictOnDelete();

            $table->foreign(['team_id', 'segment_id'], 'fk_crm_campaigns_segment')
                ->references(['team_id', 'id'])
                ->on('crm_segments')
                ->nullOnDelete();
        });

        Schema::create('crm_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('campaign_id');
            $table->string('recipient_type', 50);
            $table->unsignedBigInteger('recipient_id');
            $table->string('destination', 191);
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed', 'suppressed'])->default('pending');
            $table->string('suppression_reason', 100)->nullable();
            $table->string('external_message_id', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_campaign_recipients_team_id_id_unique');

            // Query indexes
            $table->index(['team_id', 'campaign_id', 'status'], 'crm_recipients_team_campaign_status_index');
            $table->index(['team_id', 'destination'], 'crm_recipients_team_destination_index');

            // Tier 1 Composite Foreign Key to crm_campaigns
            $table->foreign(['team_id', 'campaign_id'], 'fk_crm_recipients_campaign')
                ->references(['team_id', 'id'])
                ->on('crm_campaigns')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_campaign_recipients');
        Schema::dropIfExists('crm_campaigns');
    }
};
