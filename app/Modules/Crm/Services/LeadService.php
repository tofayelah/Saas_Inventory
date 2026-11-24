<?php

namespace App\Modules\Crm\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Customer;
use App\Modules\Crm\Models\CrmLead;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(
        protected TenantBoundaryValidator $boundaryValidator
    ) {}

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
