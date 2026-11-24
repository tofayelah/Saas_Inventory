<?php

namespace App\Modules\Crm\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmLead;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(
        protected TenantBoundaryValidator $boundaryValidator
    ) {}

    /**
     * Generate a sequential, collision-free lead number scoped to the tenant.
     * Format: LEAD-YYYYMM-XXXX
     */
    public function generateLeadNumber(int $teamId): string
    {
        $prefix = 'LEAD-' . date('Ym') . '-';

        return DB::transaction(function () use ($teamId, $prefix) {
            $latestLead = CrmLead::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('lead_number', 'like', "{$prefix}%")
                ->orderBy('lead_number', 'desc')
                ->lockForUpdate()
                ->first();

            $nextSequence = 1;
            if ($latestLead && preg_match('/-(\d+)$/', $latestLead->lead_number, $matches)) {
                $nextSequence = ((int) $matches[1]) + 1;
            }

            $leadNumber = $prefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);

            // Double check uniqueness in case of holes
            while (CrmLead::withoutGlobalScopes()->where('team_id', $teamId)->where('lead_number', $leadNumber)->exists()) {
                $nextSequence++;
                $leadNumber = $prefix . str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
            }

            return $leadNumber;
        });
    }

    /**
     * Normalize phone and email strings for uniform querying and deduplication.
     */
    public function normalizeContactDetails(array $data): array
    {
        if (isset($data['phone'])) {
            // Strip whitespace, hyphens, parentheses, and dots
            $normalizedPhone = preg_replace('/[\s\-\(\)\.]/', '', (string) $data['phone']);
            $data['phone'] = $normalizedPhone !== '' ? $normalizedPhone : null;
        }

        if (isset($data['email'])) {
            $normalizedEmail = strtolower(trim((string) $data['email']));
            $data['email'] = $normalizedEmail !== '' ? $normalizedEmail : null;
        }

        return $data;
    }

    /**
     * Check for potential duplicate leads and existing canonical customers.
     */
    public function checkDuplicates(int $teamId, ?string $phone, ?string $email, ?int $excludeLeadId = null): array
    {
        $matchedLeads = [];
        $matchedCustomers = [];

        $normalized = $this->normalizeContactDetails(['phone' => $phone, 'email' => $email]);
        $normPhone = $normalized['phone'] ?? null;
        $normEmail = $normalized['email'] ?? null;

        if (!$normPhone && !$normEmail) {
            return [
                'has_duplicates' => false,
                'matched_leads' => [],
                'matched_customers' => [],
            ];
        }

        // Check existing leads in this tenant
        $leadQuery = CrmLead::withoutGlobalScopes()
            ->where('team_id', $teamId);

        if ($excludeLeadId) {
            $leadQuery->where('id', '!=', $excludeLeadId);
        }

        $leadQuery->where(function ($q) use ($normPhone, $normEmail) {
            if ($normPhone) {
                $q->orWhere('phone', $normPhone);
            }
            if ($normEmail) {
                $q->orWhere('email', $normEmail);
            }
        });

        $matchedLeads = $leadQuery->limit(5)->get(['id', 'lead_number', 'title', 'company_name', 'phone', 'email', 'status'])->toArray();

        // Check existing canonical ERP customers in this tenant
        $customerQuery = Customer::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(function ($q) use ($normPhone, $normEmail) {
                if ($normPhone) {
                    $q->orWhere('phone', $normPhone);
                }
                if ($normEmail) {
                    $q->orWhere('email', $normEmail);
                }
            });

        $matchedCustomers = $customerQuery->limit(5)->get(['id', 'name', 'company_name', 'phone', 'email'])->toArray();

        return [
            'has_duplicates' => (!empty($matchedLeads) || !empty($matchedCustomers)),
            'matched_leads' => $matchedLeads,
            'matched_customers' => $matchedCustomers,
        ];
    }

    /**
     * Aggregate KPI metrics for the leads dashboard and filters.
     */
    public function getLeadKpis(int $teamId, ?int $warehouseId = null, ?int $assignedUserId = null): array
    {
        $baseQuery = CrmLead::withoutGlobalScopes()->where('team_id', $teamId);

        if ($warehouseId) {
            $baseQuery->where('warehouse_id', $warehouseId);
        }

        if ($assignedUserId) {
            $baseQuery->where('assigned_user_id', $assignedUserId);
        }

        $counts = (clone $baseQuery)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as count_new,
                SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) as count_contacted,
                SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) as count_qualified,
                SUM(CASE WHEN status = 'unqualified' THEN 1 ELSE 0 END) as count_unqualified,
                SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as count_converted
            ")
            ->first();

        // Calculate overdue activities attached to leads for this tenant
        $overdueActivitiesCount = CrmActivity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('related_type', 'lead')
            ->where('is_completed', false)
            ->where('due_date', '<', now())
            ->count();

        return [
            'total' => (int) ($counts->total ?? 0),
            'new' => (int) ($counts->count_new ?? 0),
            'contacted' => (int) ($counts->count_contacted ?? 0),
            'qualified' => (int) ($counts->count_qualified ?? 0),
            'unqualified' => (int) ($counts->count_unqualified ?? 0),
            'converted' => (int) ($counts->count_converted ?? 0),
            'overdue_activities' => $overdueActivitiesCount,
        ];
    }

    /**
     * Create a new Lead with tenant boundary checks.
     */
    public function createLead(int $teamId, array $data): CrmLead
    {
        $data = $this->normalizeContactDetails($data);

        // Verify warehouse boundary if provided
        if (!empty($data['warehouse_id'])) {
            $this->boundaryValidator->validateWarehouse($teamId, (int) $data['warehouse_id']);
        }

        if (empty($data['lead_number'])) {
            $data['lead_number'] = $this->generateLeadNumber($teamId);
        }

        $data['team_id'] = $teamId;
        $data['status'] = $data['status'] ?? 'new';
        $data['source'] = $data['source'] ?? 'manual';
        $data['score'] = $data['score'] ?? 0;

        return CrmLead::create($data);
    }

    /**
     * Update an existing Lead.
     */
    public function updateLead(int $teamId, CrmLead $lead, array $data): CrmLead
    {
        if ((int) $lead->team_id !== $teamId) {
            throw new TenantIsolationException("Lead does not belong to active tenant.");
        }

        $data = $this->normalizeContactDetails($data);

        if (!empty($data['warehouse_id'])) {
            $this->boundaryValidator->validateWarehouse($teamId, (int) $data['warehouse_id']);
        }

        $lead->update($data);

        return $lead->fresh();
    }

    /**
     * Add an activity (call, task, note, meeting) attached to a lead.
     */
    public function addActivity(int $teamId, CrmLead $lead, array $data): CrmActivity
    {
        if ((int) $lead->team_id !== $teamId) {
            throw new TenantIsolationException("Lead does not belong to active tenant.");
        }

        return CrmActivity::create([
            'team_id' => $teamId,
            'activity_type' => $data['activity_type'],
            'related_type' => 'lead',
            'related_id' => $lead->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'due_date' => !empty($data['due_date']) ? Carbon::parse($data['due_date']) : null,
            'is_completed' => false,
        ]);
    }

    /**
     * Toggle the completion status of an activity.
     */
    public function toggleActivityCompletion(int $teamId, CrmActivity $activity): CrmActivity
    {
        if ((int) $activity->team_id !== $teamId) {
            throw new TenantIsolationException("Activity does not belong to active tenant.");
        }

        $newCompleted = !$activity->is_completed;
        $activity->update([
            'is_completed' => $newCompleted,
            'completed_at' => $newCompleted ? now() : null,
        ]);

        return $activity->fresh();
    }

    /**
     * Convert a prospective Lead into an ERP Customer cleanly with pessimistic row locking.
     * Prevents race conditions and duplicate customer creation under concurrent requests.
     */
    public function convertLeadToCustomer(int $teamId, int $leadId, array $customerOverrides = []): Customer
    {
        return DB::transaction(function () use ($teamId, $leadId, $customerOverrides) {
            /** @var CrmLead $lead */
            $lead = CrmLead::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', $leadId)
                ->lockForUpdate()
                ->first();

            if (!$lead) {
                throw new TenantIsolationException("Lead [ID: {$leadId}] does not belong to active tenant [ID: {$teamId}].");
            }

            // Concurrency guard: If lead is already converted, return the existing customer
            if ($lead->status === 'converted' && $lead->converted_customer_id) {
                $existing = Customer::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('id', $lead->converted_customer_id)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            // Check if customer with matching phone or email already exists for this tenant
            $customer = null;
            if ($lead->phone) {
                $customer = Customer::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('phone', $lead->phone)
                    ->first();
            }

            if (!$customer && $lead->email) {
                $customer = Customer::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('email', $lead->email)
                    ->first();
            }

            if (!$customer) {
                $customerName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
                if (empty($customerName)) {
                    $customerName = $lead->company_name ?: $lead->title;
                }

                $customer = Customer::forceCreate([
                    'team_id' => $teamId,
                    'name' => $customerOverrides['name'] ?? $customerName,
                    'email' => $customerOverrides['email'] ?? $lead->email,
                    'phone' => $customerOverrides['phone'] ?? $lead->phone,
                    'company_name' => $customerOverrides['company_name'] ?? $lead->company_name,
                    'address' => $customerOverrides['address'] ?? null,
                    'is_active' => true,
                ]);
            }

            // Update lead state atomically
            $lead->update([
                'status' => 'converted',
                'converted_customer_id' => $customer->id,
                'converted_at' => now(),
            ]);

            return $customer;
        });
    }
}
