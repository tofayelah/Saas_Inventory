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
        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->enum('type', ['static', 'dynamic'])->default('static');
            $table->json('filter_criteria')->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();

            // Composite parent unique index for Tier 1 composite foreign keys
            $table->unique(['team_id', 'id'], 'crm_segments_team_id_id_unique');

            // Query index
            $table->index(['team_id', 'name'], 'crm_segments_team_name_index');
        });

        Schema::create('crm_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->enum('channel_type', ['whatsapp', 'sms', 'email']);
            $table->string('destination', 191);
            $table->enum('purpose', ['marketing', 'transactional', 'service_alert'])->default('marketing');
            $table->enum('status', ['opted_in', 'opted_out', 'dnd_national'])->default('opted_in');
            $table->string('source', 100);
            $table->text('opt_in_evidence')->nullable();
            $table->timestamp('opted_at')->useCurrent();
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_consents_team_id_id_unique');

            // Business uniqueness & query optimization indexes
            $table->unique(['team_id', 'channel_type', 'destination', 'purpose'], 'uq_crm_consents');
            $table->index(['team_id', 'destination'], 'crm_consents_team_destination_index');
            $table->index(['team_id', 'status'], 'crm_consents_team_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_consents');
        Schema::dropIfExists('crm_segments');
    }
};
