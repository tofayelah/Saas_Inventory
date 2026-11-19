# -*- coding: utf-8 -*-
"""
Sections 23 to 31 of ERP System Design Document
"""

CONTENT = r"""
---

## 23. PROPOSED ERP ARCHITECTURE (TARGET STATE)

### 23.1 Architectural Paradigm: Modular Monolith
To avoid the network latency, distributed transaction complexity, and DevOps overhead of premature microservices, the proposed target architecture is a **High-Cohesion, Low-Coupling Modular Monolith**.
- Core domains are organized into dedicated bounded contexts (Modules) within Laravel (`app/Modules/*` or PSR-4 domain directories).
- Cross-domain communication occurs strictly via Domain Events (`SalesOrderCompleted`, `GoodsReceived`, `ExpenseApproved`) and synchronous Service Contracts, rather than direct database mutations across domain boundaries.

```
+-----------------------------------------------------------------------------------+
|                            INERTIA.JS + VUE 3 CLIENT                              |
|   Platform Admin Portal   |   Tenant Workspace Shell   |   POS Offline Terminal   |
+-----------------------------------------------------------------------------------+
                                         │  HTTPS / Inertia Protocol
                                         ▼
+-----------------------------------------------------------------------------------+
|                        SECURITY & GATEWAY PIPELINE                                |
|   Rate Limiter   |   Sanctum Auth   |   Tenant Resolver   |   Strict RBAC Gating  |
+-----------------------------------------------------------------------------------+
                                         │
        ┌────────────────────────────────┴────────────────────────────────┐
        ▼                                                                 ▼
+───────────────────────────+                     +─────────────────────────────────+
|   SAAS PLATFORM PLANE     |                     |     TENANT OPERATIONAL PLANE    |
| - Tenant Provisioning     |                     | - Point of Sale (POS)           |
| - Subscription Management |                     | - Inventory & Multi-Warehouse   |
| - Plan Versioning         |                     | - Procurement & Vendor Dues     |
| - MFS & SSLCommerz Billing|                     | - Service Workshop & RMA        |
| - System Health & Metrics |                     | - Project Costing & Contracts   |
+───────────────────────────+                     | - HRM & Payroll Operations      |
        │                                         +─────────────────────────────────+
        │                                                         │
        │                                                         ▼
        │                                         +─────────────────────────────────+
        │                                         |    UNIFIED ACCOUNTING ENGINE    |
        │                                         | - Auto-Posting Event Listeners  |
        │                                         | - Double-Entry General Ledger   |
        │                                         | - Real-time COGS & Tax Vouchers |
        │                                         +─────────────────────────────────+
        │                                                         │
        └────────────────────────────────┬────────────────────────┘
                                         ▼
+-----------------------------------------------------------------------------------+
|                           PERSISTENCE & STORAGE LAYER                             |
|   Shared Database with RLS   |   Redis Cache & Queues   |   S3/MinIO Object Store |
+-----------------------------------------------------------------------------------+
```

---

## 24. PROPOSED SAAS PLATFORM ARCHITECTURE

### 24.1 Control Plane Decoupling
1. **Isolated Route Namespacing:** Complete separation of Platform Administration (`/platform/*` or dedicated subdomain `admin.inomanage.com`) from Tenant Workspaces (`app.inomanage.com` or tenant slug routes).
2. **Immutable Plan Contracts:** Introduction of `plan_versions` to ensure existing tenant subscriptions remain locked to their agreed pricing and quota terms until explicit renewal or tier migration.
3. **Automated Provisioning Pipeline:** Onboarding workflow transitioning through declarative states: `pending_payment` -> `provisioning` -> `active` -> `expiring` -> `grace_period` -> `suspended` -> `archived`.

---

## 25. PROPOSED MULTI-TENANT STRATEGY

### 25.1 Hardened Hybrid Row-Level Isolation (RLS)
To maintain operational efficiency while eliminating tenant leakage risks:
1. **Explicit Execution Context:** Replace reliance on `Auth::user()` with an explicit `TenantManager` service container singleton:
   ```php
   namespace App\Tenancy;
   
   class TenantContext
   {
       protected ?Team $activeTenant = null;
       
       public function set(Team $tenant): void { $this->activeTenant = $tenant; }
       public function get(): ?Team { return $this->activeTenant; }
       public function id(): ?int { return $this->activeTenant?->id; }
   }
   ```
2. **Database Session Variable Enforcement:** Upon resolving the tenant in HTTP middleware or queue job handlers, set a MySQL session variable:
   ```sql
   SET @current_tenant_id = 42;
   ```
3. **Database-Level Views / Virtual Private Database (VPD):** Utilize MySQL triggers and views ensuring that even raw `DB::table(...)` executions are bound by `@current_tenant_id`.
4. **Tenant-Aware Asynchronous Jobs:** Enforce that all queued jobs implement a `TenantJobInterface` that automatically serializes `team_id` and restores the tenant context before `handle()` executes.

---

## 26. PROPOSED TARGET DATABASE ARCHITECTURE

### 26.1 Schema Refactoring Objectives
1. **Consolidate Financial Subsystems:** Merge `financial_accounts` into `chart_of_accounts`. Bank, Cash, and MFS accounts become designated Asset accounts (`subtype = 'cash_and_bank'`) within the General Ledger.
2. **Introduce Plan Versioning:** Add `plan_versions` table to preserve historical pricing, feature limits, and quota allocations.
3. **Add Bangladesh VAT Infrastructure:** Add `vat_rates`, `mushak_documents`, and `withholding_certificates` to achieve legal NBR compliance.
4. **Add Company & Branch Multi-Level Hierarchy:** Separate `companies` (legal tax entity) from `branches` (physical selling outlets/stores) under the tenant `team`.

---

## 27. TARGET ENTITY RELATIONSHIP DIAGRAM (TARGET STATE)

```mermaid
erDiagram
    TENANTS ||--o{ COMPANIES : "owns legal entities"
    COMPANIES ||--o{ BRANCHES : "operates branches"
    BRANCHES ||--o{ WAREHOUSES : "fulfills from"
    TENANTS ||--o{ USERS : "employs"
    
    PLANS ||--o{ PLAN_VERSIONS : "versions"
    PLAN_VERSIONS ||--o{ TENANT_SUBSCRIPTIONS : "binds contract terms"
    TENANT_SUBSCRIPTIONS ||--o{ INVOICES : "billed through"
    INVOICES ||--o{ PAYMENTS : "settled via"

    COMPANIES ||--o{ CHART_OF_ACCOUNTS : "maintains COA"
    CHART_OF_ACCOUNTS ||--o{ BANK_ACCOUNTS : "specifies"
    CHART_OF_ACCOUNTS ||--o{ JOURNAL_ENTRY_LINES : "categorizes"
    JOURNAL_ENTRIES ||--|{ JOURNAL_ENTRY_LINES : "contains balanced lines"

    BRANCHES ||--o{ SALES_INVOICES : "issues"
    SALES_INVOICES ||--|{ SALES_INVOICE_ITEMS : "contains"
    SALES_INVOICES ||--o| MUSHAK_6_3 : "generates tax invoice"
    SALES_INVOICES ||--o{ JOURNAL_ENTRIES : "triggers auto-posting"

    BRANCHES ||--o{ PURCHASE_INVOICES : "receives"
    PURCHASE_INVOICES ||--|{ PURCHASE_INVOICE_ITEMS : "contains"
    PURCHASE_INVOICES ||--o{ JOURNAL_ENTRIES : "triggers auto-posting"

    WAREHOUSES ||--o{ INVENTORY_BATCHES : "stocks"
    INVENTORY_BATCHES ||--o{ STOCK_MOVEMENTS : "tracks changes"
```

---

## 28. PROPOSED BACKEND ARCHITECTURE

### 28.1 Automated Accounting Event-Driven Posting Engine
To resolve the critical disconnect between operational sales/purchases and formal accounting, implement an automated posting pipeline:

```
[Operational Action: POS Sale / Goods Received / Payroll]
                           │
                           ▼
              [Dispatches Domain Event]
         (e.g., SalesOrderCompletedEvent)
                           │
                           ▼
          [Auto-Posting Accounting Listener]
                           │
                           ▼
    ┌──────────────────────────────────────────────┐
    │ 1. Debit Cash/Receivable (COA #1010 / #1030) │
    │ 2. Debit Cost of Goods Sold (COA #5010)      │
    │ 3. Credit Product Sales Revenue (COA #4010)  │
    │ 4. Credit Inventory Asset Stock (COA #1040)  │
    │ 5. Credit VAT Payable Mushak 6.3 (COA #2030) │
    └──────────────────────────────────────────────┘
                           │
                           ▼
       [Atomic Journal Entry & Line Insertion]
```

### 28.2 Strict Backend Authorization Middleware
All domain route groups will be wrapped with explicit Spatie permission checks:
```php
Route::middleware(['auth:sanctum', 'tenant.status', 'can:manage-inventory'])->group(function () {
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer']);
});
```

---

## 29. PROPOSED API ARCHITECTURE

### 29.1 Modern RESTful & Inertia Standards
- **Standardized Response Envelope:**
  ```json
  {
    "success": true,
    "data": { ... },
    "meta": { "timestamp": "2026-11-18T15:30:00Z", "tenant_id": 2 },
    "error": null
  }
  ```
- **Idempotency Headers:** POS checkout and payment submission endpoints will enforce `Idempotency-Key` headers stored in Redis for 120 seconds to prevent double-billing during mobile connectivity fluctuations.
- **Rate Limiting:** Tenant-level rate limiting (60 requests/minute for reporting; 120 requests/minute for POS counter checkouts).

---

## 30. PROPOSED UI/UX DESIGN STANDARDS

### 30.1 Enterprise Design System
1. **Accessibility & Contrast:** Conformance with WCAG 2.1 AA standards; minimum contrast ratio 4.5:1 for text against slate backgrounds.
2. **High-Density POS Layout:**
   - Keyboard-only navigation (F2: Search SKU, F4: Customer Select, F8: Discount, F9: Tender Cash, Enter: Print & Finish).
   - High-contrast numerical inputs and dedicated keypad buttons for touch-screen terminals.
3. **Data Grid Standard:**
   - Persistent user column preferences stored in `localStorage`.
   - Asynchronous server-side export with floating background download progress.

---

## 31. COMPLETE ERP MODULE SPECIFICATIONS

Detailed technical specifications for the target enterprise ERP suite:

### 31.1 SaaS Platform Management
- **Centralized Tenant Directory:** Complete audit logs of all admin impersonations.
- **Automated Billing Engine:** Recurring subscription invoices generated 7 days prior to expiry with SMS and Email reminder dispatches.
- **Platform Analytics:** Real-time MRR (Monthly Recurring Revenue), Churn Rate, and Active Workspace gauges.

### 31.2 Retail & POS Counter
- **Multi-Terminal Synchronization:** Cash drawer reconciliation (Opening float, Cash drops, End-of-day register closure).
- **Offline Resilient Mode:** Service Worker caching of recent catalog SKUs with IndexedDB local queue synchronization upon network restoration.
- **Split Tendering:** Ability to tender a single invoice across multiple payment methods (e.g. ৳5,000 Cash + ৳3,000 bKash).
"""
