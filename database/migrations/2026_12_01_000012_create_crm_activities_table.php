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
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->enum('activity_type', ['call', 'meeting', 'task', 'note', 'email', 'whatsapp']);
            $table->string('related_type', 50);
            $table->unsignedBigInteger('related_id');
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_date')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Composite parent unique index
            $table->unique(['team_id', 'id'], 'crm_activities_team_id_id_unique');

            // Polymorphic & assignment query optimization indexes
            $table->index(['team_id', 'related_type', 'related_id'], 'crm_activities_team_related_index');
            $table->index(['team_id', 'assigned_user_id', 'is_completed'], 'crm_activities_team_assigned_completed_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
