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
        Schema::create('crm_support_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('case_number', 50);
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('title', 191);
            $table->text('description');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('status', ['new', 'in_progress', 'escalated_workshop', 'resolved', 'closed'])->default('new');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalated_service_ticket_id')->nullable()->constrained('service_tickets')->nullOnDelete();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_support_cases_team_id_id_unique');

            // Business uniqueness & query optimization indexes
            $table->unique(['team_id', 'case_number'], 'crm_support_cases_team_case_number_unique');
            $table->index(['team_id', 'customer_id'], 'crm_support_cases_team_customer_index');
            $table->index(['team_id', 'status'], 'crm_support_cases_team_status_index');
            $table->index(['team_id', 'assigned_user_id'], 'crm_support_cases_team_assigned_user_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_support_cases');
    }
};
