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
        Schema::create('crm_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('event_trigger', 100);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Composite parent unique index for Tier 1 composite foreign keys
            $table->unique(['team_id', 'id'], 'crm_automation_rules_team_id_id_unique');

            // Query index
            $table->index(['team_id', 'event_trigger', 'is_active'], 'crm_rules_team_trigger_active_index');
        });

        Schema::create('crm_automation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('rule_id');
            $table->string('target_type', 50);
            $table->unsignedBigInteger('target_id');
            $table->enum('execution_status', ['success', 'failed', 'skipped']);
            $table->text('log_output')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_automation_logs_team_id_id_unique');

            // Query index
            $table->index(['team_id', 'rule_id', 'created_at'], 'crm_logs_team_rule_created_index');

            // Tier 1 Composite Foreign Key to crm_automation_rules
            $table->foreign(['team_id', 'rule_id'], 'fk_crm_logs_rule')
                ->references(['team_id', 'id'])
                ->on('crm_automation_rules')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_automation_logs');
        Schema::dropIfExists('crm_automation_rules');
    }
};
