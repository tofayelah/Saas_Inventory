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
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('tokenable_id')->constrained('teams')->cascadeOnDelete();
            $table->index(['tokenable_type', 'tokenable_id', 'team_id'], 'personal_access_tokens_tokenable_team_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropIndex('personal_access_tokens_tokenable_team_index');
            $table->dropColumn('team_id');
        });
    }
};
