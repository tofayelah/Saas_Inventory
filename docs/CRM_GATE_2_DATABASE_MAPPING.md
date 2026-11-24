# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
# STAGE 2 CRM — GATE 2 DATABASE MAPPING & SCHEMA SPECIFICATION
## Comprehensive Relational Entity Mapping, B2B/B2C Classification, Financial Ledger Integrity, and Zero-Migration Strategy Analysis

**Project:** InoManage Cloud Multi-Tenant SaaS ERP  
**Repository:** `D:\Project\Saas_inventory`  
**Current Branch:** `crm/gate-1-schema`  
**Accepted Gate 1 HEAD:** `a0cd9941ffcfae6e603e0e31c8a5674916442dd4`  
**Document Status:** DRAFT FOR PROJECT OWNER APPROVAL (REVISION 2.2 — REFINED)  
**Implementation Authorization:** NOT AUTHORIZED — PLANNING ONLY  

---

## 1. EXECUTIVE SCHEMA SUMMARY & INVARIANTS

This document defines the verified relational database mapping between the **18 Gate 1 CRM tables** and the **existing canonical ERP tables**.

### 1.1 Mandatory Integration Invariants
1. **Canonical Customer Master Preservation:**  
   The existing `customers` table (`database/migrations/2026_09_10_182001_create_customers_table.php` and `2026_10_31_182446_add_opening_balance_to_customers_and_suppliers_table.php`) remains the single, authoritative master for all customer records. No secondary or shadow CRM customer master table exists.
2. **Project Costing `clients` Isolation:**  
   The `clients` table (`app/Models/Client.php`), which provides dedicated client accounting for project contracts and billings in the Project Costing subsystem, is **strictly isolated and untouched**. CRM operations do not query, insert, or link to `clients`.
3. **Double-Entry Ledger Integrity:**  
   CRM deals and customer interactions do not modify financial accounts, journal entries, or ledger balances directly. Outstanding balances, customer dues, and order histories are retrieved on a strictly **read-only basis** from canonical `sales_orders` and accounting services (`AccountingService` / `CustomerDueController`).
4. **Distinction Between Verified Facts and Schema Change Proposals:**  
   This document distinguishes between:
   - **Verified Baseline Facts:** Capabilities operational immediately under the 18 Gate 1 CRM tables and existing ERP schema without database modifications.
   - **Proposals Requiring Project Owner Approval:** Optional schema enhancements (such as explicit `customer_type` or `assigned_user_id` on the `customers` table) that require an owner-approved change request prior to execution.

---

## 2. B2B / B2C CLASSIFICATION REFINEMENT

### 2.1 Existing Schema Evidence (Verified Facts)
Inspection of the actual `customers` table migrations (`2026_09_10_182001` and `2026_10_31_182446`) and model (`app/Models/Customer.php`) reveals the exact existing schema:

```sql
CREATE TABLE customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    company_name VARCHAR(255) NULL,
    tax_number VARCHAR(255) NULL,
    address TEXT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    INDEX (team_id, is_active),
    INDEX (team_id, email),
    CONSTRAINT fk_customers_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
);
```

**Key Finding:** There is **no explicit `customer_type` or `account_classification` column** on the `customers` table in the baseline ERP schema.

### 2.2 Why `company_name IS NOT NULL` is Insufficient as a Permanent Rule
1. **Trade Name Ambiguity:** A corporate enterprise or small business in Bangladesh may register under a single trade name stored solely in `name` (e.g., "M/S Karim Enterprise") leaving `company_name` empty.
2. **Personal Employer Data:** An individual retail consumer (B2C) purchasing for personal use may supply their workplace in `company_name` for office courier delivery, which would falsely classify them as an institutional B2B Account.
3. **Sole Proprietorships:** Proprietorship businesses frequently have an individual owner name in `name`, a commercial trade license / BIN in `tax_number`, and no distinct corporate name in `company_name`.

### 2.3 Recommended Dual-Track Strategy

#### Track A: Zero-Migration Compound Heuristic (Gate 2 Default)
Without modifying the database schema in Gate 2, the CRM application implements an intelligent, multi-attribute classification heuristic:

A customer is classified as a **B2B Company Account** if ANY of the following conditions are met:
1. `company_name` is non-empty (`TRIM(company_name) != ''`).
2. `tax_number` (TIN / BIN) is non-empty (`TRIM(tax_number) != ''`).
3. The customer has one or more stakeholder records registered in `crm_contacts` (`crm_contacts.customer_id = customers.id`).

Otherwise, the customer is classified as a **B2C Individual Customer**.

#### Track B: Proposed Schema Refinement (Separate Change Proposal)
If the Project Owner prefers an immutable, explicit classification field on the canonical `customers` table, the following migration specification is prepared for separate owner approval:

```php
// PROPOSED MIGRATION SPECIFICATION (NOT EXECUTED - PENDING OWNER APPROVAL)
Schema::table('customers', function (Blueprint $table) {
    $table->enum('customer_type', ['b2b', 'b2c'])->default('b2c')->after('company_name');
    $table->foreignId('assigned_user_id')->nullable()->after('customer_type')->constrained('users')->nullOnDelete();
    $table->index(['team_id', 'customer_type'], 'customers_team_customer_type_index');
    $table->index(['team_id', 'assigned_user_id'], 'customers_team_assigned_user_index');
});
```

### 2.4 B2B Company & Stakeholder Relationship Mapping
- **B2B Account Entity:** Stored in canonical `customers`.
  - `name`: Organization name (e.g., "Square Pharmaceuticals PLC").
  - `company_name`: Registered legal entity name.
  - `tax_number`: Business Identification Number (BIN) / TIN.
  - `address`: Corporate headquarters or billing address.
- **Stakeholders & Contact Persons:** Stored in `crm_contacts`.
  - Linked via `customer_id -> customers.id`.
  - Attributes: `first_name`, `last_name`, `designation` (e.g., "Procurement Head"), `department` (e.g., "Supply Chain"), `email`, `phone`.
  - Primary Contact Flag: `is_primary = true` (enforced unique per customer).
  - Billing Contact Flag: `is_billing_contact = true` (receives payment reminders).

### 2.5 B2C Customer Mapping
- **B2C Customer Entity:** Stored in canonical `customers`.
  - `name`: Consumer's full name (e.g., "Rahim Chowdhury").
  - `company_name`: NULL.
  - `tax_number`: NULL.
  - `phone`, `email`: Direct consumer contact coordinates.
- **Stakeholders:** Typically empty (`crm_contacts` count = 0). Secondary delivery contacts can optionally be added to `crm_contacts` without breaking the individual model.

### 2.6 Duplicate Prevention Strategy
1. **B2C Duplicate Prevention:**
   - Uniqueness checked on `(team_id, phone)` and `(team_id, email)` prior to creation.
   - Normalization: Phone numbers are normalized (strip spaces, hyphens, country code prefix `+880` / `880` to local format) before duplicate comparison.
2. **B2B Duplicate Prevention:**
   - Exact match check on `(team_id, tax_number)` when TIN/BIN is provided (definitive business identity).
   - Case-insensitive, trimmed match on `company_name` and `name` within the active tenant.
   - Stakeholder duplication guard: `crm_contacts` enforces unique `(team_id, customer_id, phone)` to prevent adding the same individual contact multiple times to one company.

---

## 3. CUSTOMER 360 FINANCIAL LEDGER INTEGRITY

### 3.1 Review of Existing ERP Financial Architecture (Verified Facts)
An architectural inspection of `app/Http/Controllers/CustomerDueController.php`, `app/Services/SalesOrderService.php`, `app/Services/ReturnService.php`, and `app/Models/FinancialTransaction.php` reveals the exact ledger mechanics of InoManage ERP:

1. **Opening Balance Treatment:**
   When a customer is created with an `opening_balance > 0` in `CustomerDueController::storeCustomer()`, the ERP automatically creates an opening sales order:
   - Order Number: `INV-OPEN-xxxx` (where `xxxx` is customer ID).
   - `subtotal = opening_balance`, `total_amount = opening_balance`, `due_amount = opening_balance`.
   - `status = 'completed'`, `payment_status = 'unpaid'`.
   - An opening double-entry journal entry is recorded via `recordCustomerOpeningJournalEntry()` debiting Accounts Receivable (`1030`) and crediting Opening Equity (`3010`).
2. **Invoiced Dues:**
   Every commercial sale creates a `SalesOrder` with an authoritative `due_amount` (`total_amount - paid_amount`).
3. **Payment Settlements:**
   When payments are received via `CustomerDueController::pay()`, `SalesOrderService::recordPayment()` allocates the payment to specific `SalesOrder` records, incrementing `paid_amount` and reducing `due_amount`, while logging a deposit in `FinancialTransaction` and crediting Accounts Receivable.
4. **Returns and Refunds:**
   When a product return is processed via `ReturnService::processReturn()`, the service executes:
   `$salesOrder->update(['due_amount' => max(0, $salesOrder->due_amount - $refundAmount)])`.

### 3.2 Authoritative Balance Calculation Formula
> [!CAUTION]
> **PREVENTING DOUBLE-COUNTING OF OPENING BALANCE:**  
> Because opening balances are formalized as `INV-OPEN-xxxx` sales orders, blindly adding `$customer->opening_balance` to `sum(due_amount)` would **double-count** the opening balance for all customers registered through the UI!

The authoritative customer outstanding balance calculation implemented in `Customer360Service` must strictly follow:

```php
public function getAuthoritativeCustomerBalance(int $teamId, int $customerId): array
{
    $customer = Customer::withoutGlobalScopes()
        ->where('team_id', $teamId)
        ->findOrFail($customerId);

    // 1. Authoritative active order dues (including formal opening orders)
    $activeOrders = SalesOrder::withoutGlobalScopes()
        ->where('team_id', $teamId)
        ->where('customer_id', $customerId)
        ->where('status', '!=', 'cancelled');

    $totalBilled = (float) (clone $activeOrders)->sum('total_amount');
    $totalPaid = (float) (clone $activeOrders)->sum('paid_amount');
    $invoicedDue = (float) (clone $activeOrders)->sum('due_amount');

    // 2. Legacy/Un-invoiced opening balance check:
    // If opening_balance > 0 but NO 'INV-OPEN-%' order exists, include raw opening balance.
    $hasOpeningInvoice = SalesOrder::withoutGlobalScopes()
        ->where('team_id', $teamId)
        ->where('customer_id', $customerId)
        ->where('order_number', 'like', 'INV-OPEN-%')
        ->exists();

    $unInvoicedOpening = (!$hasOpeningInvoice) ? (float) ($customer->opening_balance ?? 0) : 0.00;
    $totalOutstandingDue = $invoicedDue + $unInvoicedOpening;

    return [
        'total_billed' => $totalBilled + $unInvoicedOpening,
        'total_paid' => $totalPaid,
        'total_due' => $totalOutstandingDue,
        'has_due' => $totalOutstandingDue > 0,
    ];
}
```

### 3.3 Strict Read-Only Presentation
- The Customer 360 profile presents `total_billed`, `total_paid`, and `total_due` on a strictly **read-only basis**.
- Any "Receive Due Payment" button in the UI redirects or posts directly to the existing, authoritative ERP endpoint: `route('dues.pay')` managed by `CustomerDueController`.
- Zero CRM-specific financial tables or ledger modifications are introduced.

---

## 4. ZERO-MIGRATION STRATEGY AUDIT (WP1 TO WP6)

The following table comprehensively maps each Work Package requirement against existing Gate 1 CRM tables and ERP baseline tables, confirming coverage and identifying any optional proposals:

| Work Package | Functional Requirement | Existing Table | Existing Model | Existing Relationships / Columns | Coverage Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **WP1: Leads** | Lead list, create, edit | `crm_leads` | `CrmLead` | All columns present (`title, first_name, last_name, company_name, email, phone, status, source, score`) | ✅ Verified 100% |
| **WP1: Leads** | Source tracking | `crm_leads` | `CrmLead` | `source` enum: `'manual', 'whatsapp', 'website', 'referral', 'campaign', 'import'` | ✅ Verified 100% |
| **WP1: Leads** | Branch & Rep Assignment | `crm_leads` | `CrmLead` | `warehouse_id -> warehouses.id`, `assigned_user_id -> users.id` | ✅ Verified 100% |
| **WP1: Leads** | Activity timeline | `crm_activities` | `CrmActivity` | Polymorphic `related_type = 'lead', related_id = crm_leads.id` | ✅ Verified 100% |
| **WP1: Leads** | Conversion to Customer | `crm_leads`, `customers` | `CrmLead`, `Customer` | `converted_customer_id`, `converted_deal_id`, `converted_at` | ✅ Verified 100% |
| **WP2: Accounts**| Canonical Customer Master | `customers` | `Customer` | `id, team_id, name, company_name, email, phone, tax_number, address, opening_balance, is_active` | ✅ Verified 100% |
| **WP2: Accounts**| Stakeholder Management | `crm_contacts` | `CrmContact` | `customer_id, first_name, last_name, designation, department, email, phone, is_primary, is_billing_contact` | ✅ Verified 100% |
| **WP2: Accounts**| B2B / B2C Classification | `customers` | `Customer` | Verified via Compound Heuristic (`company_name, tax_number, crm_contacts`). *Proposal: `customer_type` enum.* | ✅ Operational (Proposal prepared) |
| **WP2: Accounts**| Account Ownership | `customers` / `crm_deals` | `Customer` | Verified via latest deal assignment / lead creator. *Proposal: `assigned_user_id` on customers.* | ✅ Operational (Proposal prepared) |
| **WP3: Deals** | Multiple Sales Pipelines | `crm_pipelines` | `CrmPipeline` | `name, slug, is_default, is_active` | ✅ Verified 100% |
| **WP3: Deals** | Configurable Stages | `crm_pipeline_stages` | `CrmPipelineStage` | `pipeline_id, name, probability_percentage, sort_order, is_won_stage, is_lost_stage` | ✅ Verified 100% |
| **WP3: Deals** | Commercial Opportunities | `crm_deals` | `CrmDeal` | `deal_number, title, customer_id, lead_id, pipeline_id, stage_id, assigned_user_id, warehouse_id, monetary_value, currency, expected_close_date, status, loss_reason` | ✅ Verified 100% |
| **WP3: Deals** | Quoted Line Items | `crm_deal_items` | `CrmDealItem` | `deal_id, product_id, description, quantity, unit_price, discount_amount, subtotal` | ✅ Verified 100% |
| **WP3: Deals** | Quotation Linkage | `crm_deals`, `quotations`| `CrmDeal`, `Quotation`| `crm_deals.quotation_id -> quotations.id` | ✅ Verified 100% |
| **WP4: Tasks** | Calls, Meetings, Notes, Tasks | `crm_activities` | `CrmActivity` | `activity_type` enum: `'call', 'meeting', 'task', 'note', 'email', 'whatsapp'` | ✅ Verified 100% |
| **WP4: Tasks** | Due Dates & Overdue tracking | `crm_activities` | `CrmActivity` | `due_date, is_completed, completed_at, assigned_user_id` | ✅ Verified 100% |
| **WP5: 360 View**| Consolidated Customer Profile | Aggregation | Multiple | Aggregates `customers, crm_contacts, crm_deals, crm_leads, quotations, sales_orders, crm_activities` | ✅ Verified 100% |
| **WP5: 360 View**| Authoritative Due Balance | `sales_orders` | `SalesOrder` | `sum(due_amount)` + un-invoiced opening balance via `CustomerDueController` | ✅ Verified 100% |
| **WP6: Dashboard**| Metrics, Funnel, Leaderboard | Aggregation | Multiple | Aggregates indexed fields on `crm_leads, crm_deals, crm_activities` | ✅ Verified 100% |

---

## 5. RECONCILIATION SUMMARY & PROJECT OWNER DECISIONS

1. **Zero-Migration Compliance:**  
   The Core CRM functionality (WP1 to WP6) is 100% operational under the current 18 Gate 1 CRM tables and existing ERP baseline.
2. **Decisions Awaiting Owner Authorization:**
   - **Decision 1 (B2B/B2C Classification):** Accept the zero-migration compound heuristic (Track A) for Gate 2, OR authorize the separate migration proposal (Track B) to add explicit `customer_type` to `customers`.
   - **Decision 2 (Customer Ownership Assignment):** Accept deriving customer account management via active deals / lead creator (Track A), OR authorize adding `assigned_user_id` to `customers` under the same separate migration proposal.
