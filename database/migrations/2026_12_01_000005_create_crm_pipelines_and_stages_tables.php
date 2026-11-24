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
        Schema::create('crm_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Composite parent unique index for Tier 1 composite foreign keys
            $table->unique(['team_id', 'id'], 'crm_pipelines_team_id_id_unique');

            // Business uniqueness
            $table->unique(['team_id', 'slug'], 'crm_pipelines_team_slug_unique');
        });

        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedBigInteger('pipeline_id');
            $table->string('name', 100);
            $table->decimal('probability_percentage', 5, 2)->default(0.00);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_won_stage')->default(false);
            $table->boolean('is_lost_stage')->default(false);
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_pipeline_stages_team_id_id_unique');

            // Query index
            $table->index(['team_id', 'pipeline_id', 'sort_order'], 'crm_pipeline_stages_team_pipeline_sort_index');

            // Tier 1 Composite Foreign Key to crm_pipelines
            $table->foreign(['team_id', 'pipeline_id'], 'fk_crm_stages_pipeline')
                ->references(['team_id', 'id'])
                ->on('crm_pipelines')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_pipeline_stages');
        Schema::dropIfExists('crm_pipelines');
    }
};
