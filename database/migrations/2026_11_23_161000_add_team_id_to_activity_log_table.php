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
        $tableName = config('activitylog.table_name', 'activity_log');

        if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'team_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('team_id')->nullable()->after('id')->constrained('teams')->nullOnDelete();
                $table->index(['team_id', 'created_at'], 'activity_log_team_created_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = config('activitylog.table_name', 'activity_log');

        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'team_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['team_id']);
                $table->dropIndex('activity_log_team_created_idx');
                $table->dropColumn('team_id');
            });
        }
    }
};
