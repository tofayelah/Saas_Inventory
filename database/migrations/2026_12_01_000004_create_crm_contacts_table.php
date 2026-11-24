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
        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('designation', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_billing_contact')->default(false);
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_contacts_team_id_id_unique');

            // Query optimization indexes
            $table->index(['team_id', 'customer_id'], 'crm_contacts_team_customer_index');
            $table->index(['team_id', 'phone'], 'crm_contacts_team_phone_index');
            $table->index(['team_id', 'email'], 'crm_contacts_team_email_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
