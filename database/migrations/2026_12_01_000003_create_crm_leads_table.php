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
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('lead_number', 50);
            $table->string('title', 191);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name', 150)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->enum('source', ['manual', 'whatsapp', 'website', 'referral', 'campaign', 'import'])->default('manual');
            $table->enum('status', ['new', 'contacted', 'qualified', 'unqualified', 'converted'])->default('new');
            $table->integer('score')->default(0);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedBigInteger('converted_deal_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_leads_team_id_id_unique');

            // Business uniqueness & query optimization indexes
            $table->unique(['team_id', 'lead_number'], 'crm_leads_team_lead_number_unique');
            $table->index(['team_id', 'status'], 'crm_leads_team_status_index');
            $table->index(['team_id', 'phone'], 'crm_leads_team_phone_index');
            $table->index(['team_id', 'email'], 'crm_leads_team_email_index');
            $table->index(['team_id', 'assigned_user_id'], 'crm_leads_team_assigned_user_index');
            $table->index(['team_id', 'warehouse_id'], 'crm_leads_team_warehouse_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
