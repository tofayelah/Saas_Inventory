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
        Schema::create('crm_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('channel_id');
            $table->string('counterparty_type', 50);
            $table->unsignedBigInteger('counterparty_id');
            $table->string('remote_identifier', 191);
            $table->enum('status', ['open', 'pending', 'snoozed', 'closed'])->default('open');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // Composite parent unique index for Tier 1 composite foreign keys
            $table->unique(['team_id', 'id'], 'crm_conversations_team_id_id_unique');

            // Query indexes
            $table->index(['team_id', 'counterparty_type', 'counterparty_id'], 'crm_conversations_counterparty_index');
            $table->index(['team_id', 'remote_identifier'], 'crm_conversations_remote_id_index');
            $table->index(['team_id', 'status'], 'crm_conversations_status_index');

            // Tier 1 Composite Foreign Key to crm_channels
            $table->foreign(['team_id', 'channel_id'], 'fk_crm_conversations_channel')
                ->references(['team_id', 'id'])
                ->on('crm_channels')
                ->cascadeOnDelete();
        });

        Schema::create('crm_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('message_type', ['text', 'image', 'document', 'template', 'audio', 'video'])->default('text');
            $table->text('body')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('external_message_id', 191)->nullable();
            $table->enum('status', ['queued', 'sent', 'delivered', 'read', 'failed'])->default('queued');
            $table->string('error_message', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_messages_team_id_id_unique');

            // Query indexes
            $table->index(['team_id', 'conversation_id'], 'crm_messages_conversation_index');
            $table->index(['team_id', 'external_message_id'], 'crm_messages_external_id_index');
            $table->index(['team_id', 'status'], 'crm_messages_status_index');

            // Tier 1 Composite Foreign Key to crm_conversations
            $table->foreign(['team_id', 'conversation_id'], 'fk_crm_messages_conversation')
                ->references(['team_id', 'id'])
                ->on('crm_conversations')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_messages');
        Schema::dropIfExists('crm_conversations');
    }
};
