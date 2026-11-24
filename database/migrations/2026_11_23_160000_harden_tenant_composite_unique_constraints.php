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
        // 1. Quotations: Convert global unique to composite (team_id + quotation_number)
        if (Schema::hasTable('quotations')) {
            // Verify no duplicate (team_id, quotation_number) exists before indexing
            $duplicates = DB::table('quotations')
                ->select('team_id', 'quotation_number', DB::raw('COUNT(*) as count'))
                ->groupBy('team_id', 'quotation_number')
                ->having('count', '>', 1)
                ->exists();

            if ($duplicates) {
                throw new \RuntimeException('Duplicate quotations found within a tenant workspace. Cannot apply composite unique index.');
            }

            Schema::table('quotations', function (Blueprint $table) {
                $table->dropUnique(['quotation_number']);
                $table->unique(['team_id', 'quotation_number'], 'quotations_team_number_unique');
            });
        }

        // 2. Delivery Challans: Convert global unique to composite (team_id + challan_number)
        if (Schema::hasTable('delivery_challans')) {
            $duplicates = DB::table('delivery_challans')
                ->select('team_id', 'challan_number', DB::raw('COUNT(*) as count'))
                ->groupBy('team_id', 'challan_number')
                ->having('count', '>', 1)
                ->exists();

            if ($duplicates) {
                throw new \RuntimeException('Duplicate delivery challans found within a tenant workspace. Cannot apply composite unique index.');
            }

            Schema::table('delivery_challans', function (Blueprint $table) {
                $table->dropUnique(['challan_number']);
                $table->unique(['team_id', 'challan_number'], 'challans_team_number_unique');
            });
        }

        // 3. Service Tickets: Convert global unique to composite (team_id + ticket_number)
        if (Schema::hasTable('service_tickets')) {
            $duplicates = DB::table('service_tickets')
                ->select('team_id', 'ticket_number', DB::raw('COUNT(*) as count'))
                ->groupBy('team_id', 'ticket_number')
                ->having('count', '>', 1)
                ->exists();

            if ($duplicates) {
                throw new \RuntimeException('Duplicate service tickets found within a tenant workspace. Cannot apply composite unique index.');
            }

            Schema::table('service_tickets', function (Blueprint $table) {
                $table->dropUnique(['ticket_number']);
                try {
                    $table->dropIndex(['team_id', 'ticket_number']);
                } catch (\Throwable $e) {
                    // Index might not exist or differ
                }
                $table->unique(['team_id', 'ticket_number'], 'service_tickets_team_number_unique');
            });
        }

        // 4. Project Bills: Convert global unique to composite (team_id + bill_number)
        if (Schema::hasTable('project_bills')) {
            $duplicates = DB::table('project_bills')
                ->select('team_id', 'bill_number', DB::raw('COUNT(*) as count'))
                ->groupBy('team_id', 'bill_number')
                ->having('count', '>', 1)
                ->exists();

            if ($duplicates) {
                throw new \RuntimeException('Duplicate project bills found within a tenant workspace. Cannot apply composite unique index.');
            }

            Schema::table('project_bills', function (Blueprint $table) {
                $table->dropUnique(['bill_number']);
                $table->unique(['team_id', 'bill_number'], 'project_bills_team_number_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('project_bills')) {
            Schema::table('project_bills', function (Blueprint $table) {
                $table->dropUnique('project_bills_team_number_unique');
                $table->unique('bill_number');
            });
        }

        if (Schema::hasTable('service_tickets')) {
            Schema::table('service_tickets', function (Blueprint $table) {
                $table->dropUnique('service_tickets_team_number_unique');
                $table->unique('ticket_number');
                $table->index(['team_id', 'ticket_number']);
            });
        }

        if (Schema::hasTable('delivery_challans')) {
            Schema::table('delivery_challans', function (Blueprint $table) {
                $table->dropUnique('challans_team_number_unique');
                $table->unique('challan_number');
            });
        }

        if (Schema::hasTable('quotations')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropUnique('quotations_team_number_unique');
                $table->unique('quotation_number');
            });
        }
    }
};
