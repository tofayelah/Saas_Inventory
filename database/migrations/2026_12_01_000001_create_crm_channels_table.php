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
        Schema::create('crm_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->enum('channel_type', ['whatsapp', 'sms', 'email']);
            $table->string('name', 100);
            $table->string('identifier', 191);
            $table->longText('credentials')->nullable();
            $table->string('webhook_secret', 191)->nullable();
            $table->unsignedInteger('rate_limit_per_second')->default(80);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            // Composite parent unique index for Tier 1 composite foreign keys
            $table->unique(['team_id', 'id'], 'crm_channels_team_id_id_unique');

            // Business uniqueness & query optimization indexes
            $table->unique(['team_id', 'channel_type', 'identifier'], 'crm_channels_team_type_identifier_unique');
            $table->index(['team_id', 'channel_type', 'is_active'], 'crm_channels_team_type_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_channels');
    }
};
