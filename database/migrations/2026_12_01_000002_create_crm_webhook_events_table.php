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
        Schema::create('crm_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('event_scope_key', 100)->default('global');
            $table->string('external_event_id', 191)->index();
            $table->string('payload_hash', 64)->index();
            $table->json('headers')->nullable();
            $table->longText('payload');
            $table->enum('status', ['pending', 'processed', 'failed', 'duplicate', 'unmatched_channel'])->default('pending');
            $table->text('error_details')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Normalized composite unique index for event deduplication & replay defense
            $table->unique(['provider', 'event_scope_key', 'external_event_id'], 'uq_crm_webhook_events');

            // Audit and pruning query indexes
            $table->index(['provider', 'payload_hash'], 'crm_webhook_events_provider_hash_index');
            $table->index(['team_id', 'status'], 'crm_webhook_events_team_status_index');
            $table->index('created_at', 'crm_webhook_events_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_webhook_events');
    }
};
