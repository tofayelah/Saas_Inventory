# InoManage Cloud Multi-Tenant SaaS ERP
# CRM and Core ERP Integration Specifications

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** System Integration Specification  
**Path:** `docs/crm/CRM_ERP_INTEGRATION.md`

---

## 1. Customer Master Unification & Project Costing Boundary

A central architectural mandate of InoManage Cloud ERP is **Zero Counterparty Duplication**. The platform strictly prohibits maintaining separate customer tables between CRM and ERP.

### 1.1 Canonical Master: `customers` Table
* The existing `customers` table (created in `2026_09_10_182001_create_customers_table.php`) is the **sole canonical customer master** for all CRM, POS, Sales Orders, Invoicing, and Accounting operations.
* B2B accounts populate `company_name` and link to multiple stakeholders via `crm_contacts`.
* B2C consumers leave `company_name` null, where `name` represents the individual.

### 1.2 Strict Boundary: `clients` Table Left Untouched
* In `2026_10_14_110000_create_project_costing_and_billing_tables.php`, a `clients` table was created specifically for project costing.
* **Architectural Invariant:** The `clients` table remains **completely untouched and outside the scope of CRM Gate 1**. CRM will neither read, write, nor alter `clients`. Any future unification of `clients` into `customers` must occur via an independent, separately approved ERP core migration project.

---

## 2. Deal Items, Quotation Generation & Revision History

The transition from a commercial deal opportunity to an ERP sale includes granular product line-item tracking and full quotation revision history:

```mermaid
flowchart LR
    A[CRM Deal<br/>crm_deals] --> B[Deal Line Items<br/>crm_deal_items]
    B -->|Generate Quote| C[ERP Quotations<br/>quotations (deal_id)]
    C --> D[Quotation Items<br/>quotation_items]
    C -->|Revisions V1, V2| C
    C -->|Customer Accepts| E[Sales Order<br/>sales_orders]
    E --> F[Invoicing & Ledger]
```

### 2.1 Deal Line Items & Quotation Revisions
* Deals track proposed products, quantities, and target unit prices in `crm_deal_items`.
* **Quotation Revision History:** Quotations reference deals via `quotations.deal_id`. A single deal can have multiple historical quotation revisions (`$deal->quotations`), allowing sales reps to track negotiation iterations. The `crm_deals.quotation_id` column stores a reference to the currently accepted/active quotation.
* When a sales rep clicks **"Generate Quotation"**:
  1. An official ERP `quotations` record is generated with a composite unique `quotation_number` (`team_id, quotation_number`).
  2. Each `crm_deal_items` record is copied into `quotation_items`, linking `product_id`, `quantity`, and `unit_price`.
  3. The deal's `quotation_id` is updated.
* If a quotation is updated or revised in core ERP, the `QuotationUpdated` domain event triggers `SyncWonDealToQuotation`, keeping the deal's estimated value and stage aligned.

---

## 3. Financial Truth & Accounting Integration

The CRM module adheres strictly to the **Single Source of Financial Truth** invariant.

### 3.1 Accounting Boundaries
* **No Shadow Ledgers:** The CRM module **never** calculates or stores customer credit balances, outstanding dues, or transaction histories independently.
* **Real-Time Balance Inquiries:** When rendering the Customer 360 view or CRM sidebar, the CRM queries the core ERP accounting service contracts (`CustomerDueService` / general ledger) dynamically.
* **Credit Limit Warnings:** If a customer has overdue invoices exceeding their credit limit, the CRM Unified Inbox context sidebar surfaces a prominent warning badge, preventing unauthorized deals.

---

## 4. Support Cases vs Workshop Service Tickets

A critical distinction exists between post-sale customer support inquiries and physical hardware workbench repairs.

| Dimension | CRM Customer Support Cases (`crm_support_cases`) | Workshop Service Tickets (`service_tickets`) |
| :--- | :--- | :--- |
| **Primary Domain** | Customer service, inquiry handling, billing disputes, delivery tracking. | Physical bench hardware inspection, device disassembly, component repair. |
| **Personnel** | Customer Support Agents, Account Managers. | Hardware Service Technicians, Workshop Engineers. |
| **Core Entity** | Inquiry, SLA deadline, communication thread. | Physical device (`device_name`, `serial_no`), repair status, diagnostic notes. |
| **Inventory Link** | None. Purely informational and relational. | Directly issues spare parts from warehouse inventory (`service_ticket_items`). |
| **Billing Impact** | General customer goodwill, refund requests, warranty checks. | Generates repair bills, technician labor fees, and replacement parts charges. |

### 4.1 Escalation Bridge Architecture
When a customer contacts support reporting damaged or malfunctioning equipment:
1. The support agent handles the initial case in `crm_support_cases`.
2. If remote troubleshooting fails, the agent clicks **"Escalate to Workshop"**.
3. The system creates a new record in the ERP `service_tickets` table:
   * Maps `customer_id` and contact phone.
   * Assigns target `warehouse_id` (filtered by `type = 'service_center'`).
   * Captures device serial number, physical condition, and accessories.
4. The newly created `service_tickets.id` is linked to `crm_support_cases.escalated_service_ticket_id`.
5. Technicians work the ticket in the existing Workshop Workbench, while customer updates are seamlessly reflected back into the CRM case timeline.

---

## 5. Centralized Tenant Validation for Legacy ERP Foreign Keys

Because existing core ERP tables (`customers`, `warehouses`, `quotations`, `service_tickets`) do not have composite unique indexes on `(team_id, id)` and `users` lacks a `team_id` column, CRM models enforce centralized validation via `TenantBoundaryValidator`:

```php
class TenantBoundaryValidator
{
    public function validateCrmDeal(CrmDeal $deal): void
    {
        $this->validateCustomer($deal->team_id, $deal->customer_id);
        $this->validateWarehouse($deal->team_id, $deal->warehouse_id);
        $this->validateAssignedUser($deal->team_id, $deal->assigned_user_id);
        if ($deal->quotation_id) {
            $this->validateQuotation($deal->team_id, $deal->quotation_id);
        }
    }
}
```

### 5.1 Bulk Operations & Concurrency Safeguards
* **Bulk Operations:** Direct mass updates (`DB::table()->update()`) bypass Eloquent hooks. All CRM bulk updates/deletions must execute through dedicated Action/Repository classes that enforce explicit `WHERE team_id = ?` scopes on all update targets and join dependencies.
* **Concurrency Protection:** Critical state transitions (lead conversion, deal won to quotation generation, campaign dispatch) execute inside database transactions utilizing pessimistic row locks (`lockForUpdate()`) to prevent race conditions.

---
*Next: Queue architecture, rate limiters, and background workers are detailed in `docs/crm/CRM_INFRASTRUCTURE_DESIGN.md`.*
