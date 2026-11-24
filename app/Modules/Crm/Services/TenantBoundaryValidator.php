<?php

namespace App\Modules\Crm\Services;

use App\Exceptions\TenantIsolationException;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\ServiceTicket;
use App\Models\Warehouse;
use App\Modules\Crm\Models\CrmContact;
use App\Modules\Crm\Models\CrmDeal;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmSupportCase;
use Illuminate\Database\Eloquent\Model;

class TenantBoundaryValidator
{
    /**
     * Polymorphic entity alias allowlist to fully qualified model mapping.
     */
    protected const ALLOWED_POLYMORPHIC_TYPES = [
        'customer' => Customer::class,
        'lead' => CrmLead::class,
        'deal' => CrmDeal::class,
        'contact' => CrmContact::class,
        'case' => CrmSupportCase::class,
        Customer::class => Customer::class,
        CrmLead::class => CrmLead::class,
        CrmDeal::class => CrmDeal::class,
        CrmContact::class => CrmContact::class,
        CrmSupportCase::class => CrmSupportCase::class,
    ];

    /**
     * Validate that the referenced customer belongs to the active tenant.
     */
    public function validateCustomer(int $teamId, ?int $customerId): void
    {
        if ($customerId === null) {
            return;
        }

        $exists = Customer::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $customerId)
            ->exists();

        if (!$exists) {
            throw new TenantIsolationException("Referenced Customer [ID: {$customerId}] does not belong to the active tenant.");
        }
    }

    /**
     * Validate that the referenced warehouse belongs to the active tenant and is active.
     */
    public function validateWarehouse(int $teamId, ?int $warehouseId): void
    {
        if ($warehouseId === null) {
            return;
        }

        $exists = Warehouse::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->where('id', $warehouseId)
            ->exists();

        if (!$exists) {
            throw new TenantIsolationException("Referenced Warehouse [ID: {$warehouseId}] does not belong to the active tenant or is inactive.");
        }
    }

    /**
     * Validate that the referenced quotation belongs to the active tenant.
     */
    public function validateQuotation(int $teamId, ?int $quotationId): void
    {
        if ($quotationId === null) {
            return;
        }

        $exists = Quotation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $quotationId)
            ->exists();

        if (!$exists) {
            throw new TenantIsolationException("Referenced Quotation [ID: {$quotationId}] does not belong to the active tenant.");
        }
    }

    /**
     * Validate that the referenced service ticket belongs to the active tenant.
     */
    public function validateServiceTicket(int $teamId, ?int $serviceTicketId): void
    {
        if ($serviceTicketId === null) {
            return;
        }

        $exists = ServiceTicket::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $serviceTicketId)
            ->exists();

        if (!$exists) {
            throw new TenantIsolationException("Referenced Service Ticket [ID: {$serviceTicketId}] does not belong to the active tenant.");
        }
    }

    /**
     * Validate that the referenced product belongs to the active tenant.
     */
    public function validateProduct(int $teamId, ?int $productId): void
    {
        if ($productId === null) {
            return;
        }

        $exists = Product::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $productId)
            ->exists();

        if (!$exists) {
            throw new TenantIsolationException("Referenced Product [ID: {$productId}] does not belong to the active tenant.");
        }
    }

    /**
     * Validate that the assigned user holds an active membership in the active tenant.
     */
    public function validateAssignedUser(int $teamId, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $isMember = Membership::where('team_id', $teamId)
            ->where('user_id', $userId)
            ->exists();

        if (!$isMember) {
            throw new TenantIsolationException("Assigned User [ID: {$userId}] is not an authorized member of the active tenant.");
        }
    }

    /**
     * Validate polymorphic targets:
     * 1. Reject arbitrary/un-allowlisted model classes.
     * 2. Resolve model and verify tenant ownership strictly matches teamId.
     */
    public function validatePolymorphicTarget(int $teamId, string $targetType, int $targetId): void
    {
        if (!isset(self::ALLOWED_POLYMORPHIC_TYPES[$targetType])) {
            throw new TenantIsolationException("Polymorphic target type [{$targetType}] is not an allowlisted CRM entity.");
        }

        $modelClass = self::ALLOWED_POLYMORPHIC_TYPES[$targetType];

        /** @var Model $model */
        $target = $modelClass::withoutGlobalScopes()
            ->where('id', $targetId)
            ->first();

        if (!$target) {
            throw new TenantIsolationException("Referenced polymorphic target [{$targetType} #{$targetId}] does not exist.");
        }

        // Verify team_id on the target model
        if (!isset($target->team_id) || (int) $target->team_id !== $teamId) {
            throw new TenantIsolationException("Referenced polymorphic target [{$targetType} #{$targetId}] does not belong to the active tenant.");
        }
    }
}
