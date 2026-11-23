<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('projects')) {
            // 1. Pre-flight integrity check: Verify no duplicate (team_id, project_code) exists
            $duplicates = DB::table('projects')
                ->select('team_id', 'project_code', DB::raw('COUNT(*) as count'))
                ->groupBy('team_id', 'project_code')
                ->having('count', '>', 1)
                ->exists();

            if ($duplicates) {
                throw new \RuntimeException('Duplicate project codes found within a tenant workspace. Cannot apply composite unique index.');
            }

            // 2. Safely drop global unique index and replace with tenant-aware composite unique index
            Schema::table('projects', function (Blueprint $table) {
                $table->dropUnique(['project_code']);

                try {
                    $table->dropIndex(['team_id', 'project_code']);
                } catch (\Throwable $e) {
                    // Non-unique composite index might not exist or differ by database engine
                }

                $table->unique(['team_id', 'project_code'], 'projects_team_code_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropUnique('projects_team_code_unique');
                $table->unique('project_code');
                $table->index(['team_id', 'project_code']);
            });
        }
    }
};
