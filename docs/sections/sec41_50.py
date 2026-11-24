# -*- coding: utf-8 -*-
"""
Sections 41 to 50 of ERP System Design Document
"""

CONTENT = r"""
---

## 41. SYSTEM WORKFLOW DIAGRAMS (21 CORE LIFECYCLE FLOWCHARTS)

### Workflow 1: Tenant Registration
```mermaid
flowchart TD
    A[Visitor visits Landing Page /signup] --> B[Submits Name, Phone, Email, Password, Company Name]
    B --> C[Validate Form & Bangladeshi Mobile 01XXXXXXXXX]
    C --> D[Create User & Tenant Team Record]
    D --> E[Provision 3-Day Free Trial Subscription]
    E --> F[Send 6-Digit SMS OTP via BulkSMSBD]
    F --> G[User Submits OTP]
    G --> H{OTP Valid?}
    H -- No --> I[Show Error / Resend Option]
    H -- Yes --> J[Mark phone_verified_at & Redirect to /dashboard]
```

### Workflow 2: Tenant Onboarding
```mermaid
flowchart TD
    A[User Lands on Dashboard First Time] --> B[Onboarding Wizard Triggered]
    B --> C[Step 1: Set Company Details, Legal Name & Tax ID]
    C --> D[Step 2: Designate Main Warehouse Hub]
    D --> E[Step 3: Auto-Seed Standard Chart of Accounts]
    E --> F[Step 4: Configure Cash & Bank Accounts]
    F --> G[Step 5: Import Opening Stock / Products]
    G --> H[Onboarding Completed - Full Workspace Unlocked]
```

### Workflow 3: Company Creation
```mermaid
flowchart TD
    A[Tenant Admin opens Settings/Company] --> B[Submits Legal Name, BIN/TIN, Address, Logo]
    B --> C[Validate Unique Tax ID per Tenant]
    C --> D[Update Team / Company Entity]
    D --> E[Cache Flush for Shared Inertia Site Props]
    E --> F[Company Details Synchronized on All PDF Invoices]
```

### Workflow 4: Branch Creation
```mermaid
flowchart TD
    A[Tenant Admin opens Settings/Branches] --> B[Submits Branch Name, Code, Location, Hub Type]
    B --> C[Check Plan Limit for max_warehouses / branches]
    C --> D{Limit Reached?}
    D -- Yes --> E[Block with Upgrade Prompt]
    D -- No --> F[Create Branch & Default Warehouse Hub]
    F --> G[Assign Cashiers & Branch Managers via team_user]
```

### Workflow 5: Plan Creation
```mermaid
flowchart TD
    A[Super-Admin navigates to /admin/plans] --> B[Clicks Add New Subscription Plan]
    B --> C[Enters Name, Monthly BDT, Annual BDT, Quotas, Feature Flags]
    C --> D[Auto-Generate URL Slug]
    D --> E[Insert Record into plans & plan_versions]
    E --> F[Flush superadmin_active_plans Cache]
    F --> G[New Plan Appears on Public /pricing & Tenant /subscription]
```

### Workflow 6: Subscription Activation
```mermaid
flowchart TD
    A[Tenant selects Plan & Billing Cycle] --> B[Selects Payment Method: bKash/Nagad/Rocket]
    B --> C[Tenant executes Mobile Transfer & submits TrxID]
    C --> D[Record Created in subscription_payments as Pending]
    D --> E[Super-Admin reviews in /admin/payments]
    E --> F{Payment Verified?}
    F -- No --> G[Mark Rejected with Reason / Notify Tenant]
    F -- Yes --> H[Mark Approved & Call SubscriptionService::approvePayment]
    H --> I[Extend tenant_subscriptions ends_at by 1 or 12 Months]
    I --> J[Set teams.status = active & Remove Paywall Gates]
```

### Workflow 7: Subscription Renewal
```mermaid
flowchart TD
    A[System Daily Scheduler checks ends_at] --> B[7 Days to Expiry: Send SMS & Email Notice]
    B --> C[Tenant opens /subscription and initiates renewal]
    C --> D[Tenant completes MFS TrxID or SSLCommerz payment]
    D --> E[Payment Approved]
    E --> F[Calculate New ends_at from current ends_at + period]
    F --> G[Subscription renewed seamlessly without downtime]
```

### Workflow 8: Plan Upgrade
```mermaid
flowchart TD
    A[Tenant selects Higher Plan Tier] --> B[Calculate Prorated Difference in BDT]
    B --> C[Tenant submits Payment for Prorated Amount]
    C --> D[Payment Approved by Super-Admin]
    D --> E[Immediate Resource Quota Expansion max_products, etc.]
    E --> F[Features updated immediately in session props]
```

### Workflow 9: Plan Downgrade
```mermaid
flowchart TD
    A[Tenant requests Lower Plan Tier] --> B[Check Current Resource Usage vs Lower Limits]
    B --> C{Usage exceeds lower tier?}
    C -- Yes --> D[Block Downgrade: Must archive excess SKUs/users first]
    C -- No --> E[Schedule Downgrade for Next Billing Cycle]
    E --> F[New lower quotas apply upon next renewal date]
```

### Workflow 10: Subscription Expiry
```mermaid
flowchart TD
    A[Current Time exceeds ends_at] --> B[CheckTenantStatus identifies isExpired() == true]
    B --> C{Within 3-Day Grace Period?}
    C -- Yes --> D[Show Warning Banner; Allow Read/Write]
    C -- No --> E[Redirect all requests to /subscription]
    E --> F[Tenant Users locked out until payment approved]
```

### Workflow 11: User Invitation
```mermaid
flowchart TD
    A[Tenant Admin opens Settings/Team] --> B[Submits Colleague Email & Role]
    B --> C[Check max_users Plan Quota]
    C --> D{Quota Exceeded?}
    D -- Yes --> E[Prompt Admin to Upgrade Plan]
    D -- No --> F[Create team_invitations record & Send Invite Email]
    F --> G[Invited User clicks link, registers / signs in]
    G --> H[Attach user to team_user with assigned Spatie role]
```

### Workflow 12: Role and Permission Assignment
```mermaid
flowchart TD
    A[Tenant Admin selects Team Member] --> B[Selects Role: Owner, Admin, Staff, Viewer]
    B --> C[Optionally toggle granular permission overrides]
    C --> D[Update model_has_roles and model_has_permissions scoped by team_id]
    D --> E[Flush user permission cache user_auth_rp_{id}_{team_id}]
    E --> F[New permissions apply on next page visit]
```

### Workflow 13: Product Creation
```mermaid
flowchart TD
    A[User opens /products/create] --> B[Enters Name, SKU, Barcode, Prices, Category, Brand]
    B --> C[Check max_products Plan Quota]
    C --> D{Quota Exceeded?}
    D -- Yes --> E[Block Save with Plan Upgrade Error]
    D -- No --> F[Atomic Transaction: Save Product & Variants]
    F --> G[Initialize 0-stock levels in active warehouses]
    G --> H[Generate Code128 Barcode Image via Picqer]
```

### Workflow 14: Purchase Order
```mermaid
flowchart TD
    A[Procurement Officer opens /purchases/create] --> B[Selects Supplier & Destination Warehouse]
    B --> C[Adds Line Items, Quantities, Expected Unit Costs]
    C --> D[Check max_purchase_orders Plan Quota]
    D --> E[Save purchase_orders as draft / confirmed]
    E --> F[Generate PDF PO for Supplier Dispatch]
```

### Workflow 15: Goods Receiving
```mermaid
flowchart TD
    A[Shipment Arrives at Warehouse] --> B[Warehouse Staff inspects PO items]
    B --> C[Enter Received Quantities, Batch Numbers, Expiry Dates]
    C --> D[Generate Product Serial / IMEI records if serialized]
    D --> E[Update PO Status: received / partial]
    E --> F[Call StockService: increment stock_levels & log stock_movements]
    F --> G[Trigger Accounting Auto-Posting: Debit Inventory, Credit AP]
```

### Workflow 16: Inventory Update (Adjustments & Transfers)
```mermaid
flowchart TD
    A[Staff opens Inventory Adjustment / Transfer] --> B{Operation Type?}
    B -- Physical Count Adjustment --> C[Submit Count Difference (+ / -)]
    C --> D[Log stock_movements type adjustment & Update stock_levels]
    D --> E[Auto-Post: Debit/Credit Inventory, Credit/Debit Shrinkage Expense]
    B -- Inter-Warehouse Transfer --> F[Select Origin & Destination Warehouses]
    F --> G[Verify Source Available Quantity]
    G --> H[Decrement Source WH & Increment Destination WH]
    H --> I[Generate Mushak 6.5 Transfer Challan PDF]
```

### Workflow 17: POS Retail Sales
```mermaid
flowchart TD
    A[Cashier scans barcode at /pos] --> B[Add Product to Cart & Select Warehouse Hub]
    B --> C[Verify Available Quantity in stock_levels]
    C --> D[Input Tendered Cash / MFS Amount]
    D --> E[Calculate Change Due]
    E --> F[Submit POS Transaction Atomic Block]
    F --> G[Create sales_orders and sales_order_items]
    G --> H[Decrement stock_levels and create stock_movements type sale]
    H --> I[Deposit funds into Financial Account]
    I --> J[Auto-Post Balanced Journal Entry to GL]
    J --> K[Generate Mushak 6.3 Tax Invoice & Thermal Slip Print]
```

### Workflow 18: Sales Return
```mermaid
flowchart TD
    A[Customer brings back item with Invoice] --> B[Staff opens /returns/create]
    B --> C[Select original Sales Order & Return Items]
    C --> D[Check Return Condition: Restock vs Damaged]
    D --> E[Create product_returns record]
    E --> F[Restock: Increment stock_levels & log movement]
    F --> G[Issue Cash Refund or Credit Note to Customer Due Ledger]
    G --> H[Auto-Post: Debit Sales Return/COGS, Credit Cash/AR]
    H --> I[Generate Mushak 6.7 Credit Note PDF]
```

### Workflow 19: Purchase Return
```mermaid
flowchart TD
    A[Defective items identified in Warehouse] --> B[Staff opens Purchase Return]
    B --> C[Select Supplier & Original PO]
    C --> D[Deduct Stock from Warehouse via stock_movements]
    D --> E[Reduce Supplier Payable or Record Refund Voucher]
    E --> F[Auto-Post: Debit Accounts Payable, Credit Inventory Stock]
    F --> G[Generate Mushak 6.8 Debit Note PDF]
```

### Workflow 20: Accounting Journal Posting
```mermaid
flowchart TD
    A[Accountant opens /accounting/journal/create] --> B[Enters Date, Narration, Reference]
    B --> C[Adds Line Items with Chart of Account IDs]
    C --> D[Enter Debit and Credit Amounts]
    D --> E[System validates Total Debits == Total Credits]
    E --> F{Debits == Credits?}
    F -- No --> G[Show Unbalanced Error with Difference Amount]
    F -- Yes --> H[Atomic Insert into journal_entries & journal_entry_lines]
    H --> I[Real-time updates to Trial Balance, P&L, Balance Sheet]
```

### Workflow 21: Financial Period Closing
```mermaid
flowchart TD
    A[Accountant opens Fiscal Year Management] --> B[Verify all bank reconciliations complete]
    B --> C[Run Final Trial Balance & P&L Audit]
    C --> D[Execute Period Closing Procedure]
    D --> E[Auto-Post Closing Entries: Zero out Revenue & Expense accounts]
    E --> F[Net Income transferred to Retained Earnings COA #3020]
    F --> G[Mark Fiscal Year as Closed is_closed = true]
    G --> H[Lock Journal Entries in closed period from modification]
```

---

## 42. EXISTING VS TARGET ARCHITECTURE GAP ANALYSIS

| Domain | Current Implementation (Existing) | Target Enterprise Architecture (Proposed) | Strategic Gap Severity | Action Required |
|---|---|---|---|---|
| **Multi-Tenancy** | Row-level discriminator (`team_id`) relying on `Auth::check()`. Fails in CLI/queue contexts. | Context-aware `TenantManager` singleton with MySQL session variables and tenant-aware job serialization. | **HIGH** | Refactor `TenantScope` and implement `TenantAwareJob` base class. |
| **RBAC Enforcement** | Spatie roles seeded, but zero backend route or controller authorization checks. UI hiding only. | Strict backend middleware gating (`can:manage-*`) and controller policy authorization. | **CRITICAL** | Wrap all routes in `web.php` with permission middleware; implement model policies. |
| **Financial System** | Dual disconnected subsystems (`financial_accounts` vs `chart_of_accounts`). Operational sales do not post to GL. | Unified double-entry GL where bank/cash accounts are child asset accounts in COA. Automatic event-driven postings. | **CRITICAL** | Merge financial accounts into COA and implement `AccountingPostingService` event listeners. |
| **Plan Versioning** | In-place mutation of `plans` table immediately alters quotas and pricing for active subscribers. | Immutable `plan_versions` table preserving historical subscriber contracts and quota terms. | **HIGH** | Create `plan_versions` migration and link `tenant_subscriptions` to version IDs. |
| **NBR VAT Compliance**| BDT currency and tax percentage fields exist, but zero statutory NBR Mushak documents generated. | Full Bangladesh VAT suite: automated Mushak 6.3, 6.5, 6.6, 6.7, 6.8, and 9.1 returns. | **HIGH** | Implement `MushakService` and create statutory mPDF templates. |
| **Offline POS Mode** | Online-only Inertia.js interface; network drop blocks counter sales completely. | Service Worker caching and IndexedDB queue with background sync upon reconnection. | **MEDIUM** | Implement PWA Service Worker and IndexedDB transaction cache. |
| **Automated Testing** | 2 basic skeleton tests; zero unit or integration coverage for multi-tenancy or transactions. | Comprehensive test suite covering tenant isolation, RBAC boundaries, and financial postings. | **HIGH** | Build 100+ feature tests validating multi-tenant boundaries and financial integrity. |

---

## 43. DEVELOPMENT ROADMAP (PHASED EXECUTION PLAN)

### Phase 0: Discovery, Architecture Approval & Hardening
- Complete technical audit and sign-off on `ERP_SYSTEM_DESIGN.md`.
- Purge hardcoded super-admin credentials from `UserAndTenantSeeder.php` (`SEC-001`).
- Establish local staging environment with automated CI/CD linting.

### Phase 1: Security & RBAC Hardening
- Implement backend Spatie permission middleware (`can:`) across all 300+ routes in `routes/web.php`.
- Implement Laravel Model Policies for Products, Orders, Warehouses, Accounting, and HR.
- Refactor `TenantScope` to bind to explicit `TenantContext` service container.

### Phase 2: Unified Double-Entry Accounting Engine
- Migrate `financial_accounts` into `chart_of_accounts` as dedicated cash/bank asset sub-ledgers.
- Implement domain event listeners (`SalesOrderCompleted`, `PurchaseOrderReceived`, `ExpenseApproved`) to automatically generate balanced journal entries.
- Refactor Financial Reports (Trial Balance, P&L, Balance Sheet) to reflect live operational postings.

### Phase 3: Plan Versioning & Advanced SaaS Billing
- Deploy `plan_versions` table and refactor `AdminPlanController` to create new versions on edit.
- Implement automated subscription grace periods (3 days) and recurring invoice generation.
- Integrate automated SSLCommerz webhook reconciliation with fallback IPN processing.

### Phase 4: Bangladesh NBR VAT & Regulatory Compliance
- Build statutory NBR document generators: Mushak 6.3 (Tax Invoice), Mushak 6.5 (Transfer Challan), Mushak 6.7 (Credit Note), Mushak 6.8 (Debit Note).
- Create monthly Mushak 9.1 VAT Return compilation engine with exportable Excel schedules.

### Phase 5: POS Counter Modernization & Offline Resilience
- Implement Service Worker and IndexedDB local cache for POS offline cart building and receipt printing.
- Add keyboard shortcut accelerators and split-tender payment modal.

### Phase 6: Multi-Branch & Advanced Inventory Logistics
- Introduce Company -> Branch -> Warehouse relational hierarchy under Tenant.
- Implement automated reorder point calculation and low-stock replenishment purchase requisitions.
- Add batch tracking and expiry date alerts for pharmaceuticals and perishables.

### Phase 7: Enterprise Production Hardening & SRE
- Dockerize application stack (Nginx, PHP-FPM, Worker, Scheduler, Redis, MariaDB).
- Configure automated nightly encrypted off-site database backups to AWS S3 / Cloudflare R2.
- Implement centralized exception logging via Sentry and performance monitoring via Prometheus/Grafana.

---

## 44. TESTING & VERIFICATION STRATEGY

### 44.1 Automated Multi-Tenant Test Suite
1. **Tenant Isolation Tests:**
   - Create Tenant A and Tenant B.
   - Assert Tenant A user cannot read, update, or delete products or orders belonging to Tenant B via HTTP endpoints.
   - Assert queue jobs running without active session correctly scope queries to payload tenant ID.
2. **Double-Entry Accounting Invariant Tests:**
   - Execute POS sale, purchase receipt, and payroll payment.
   - Assert every generated journal entry satisfies `SUM(debit) == SUM(credit)`.
   - Assert trial balance total debits equal total credits.
3. **Paywall & Quota Enforcement Tests:**
   - Populate tenant products to `max_products` quota limit.
   - Assert next product creation returns HTTP 403 Forbidden with upgrade prompt.

---

## 45. DEPLOYMENT & ROLLBACK STRATEGY

### 45.1 Zero-Downtime Deployment Protocol
1. **Maintenance Mode Bypass:** Use `artisan down --secret="DEPLOY_BYPASS_TOKEN"`.
2. **Atomic Code Switch:** Symlink switching using Deployer or GitHub Actions.
3. **Database Migration Safety:** Migrations must be backward-compatible (e.g. adding columns as nullable first before adding constraints).
4. **Rollback Procedure:** Automated rollback to previous release directory and database migration rollback (`artisan migrate:rollback --step=1`) upon health check failure.

---

## 46. RISKS AND MITIGATION REGISTER

| Risk ID | Description | Severity | Impact | Mitigation Strategy |
|---|---|---|---|---|
| **RSK-01** | Cross-tenant data leakage via queue workers | **HIGH** | Data breach, regulatory fine | Enforce `TenantAwareJob` abstract base class for all background jobs. |
| **RSK-02** | Accounting imbalance due to manual unposted entries | **HIGH** | Financial misstatement | Implement automated event-driven journal posting engine. |
| **RSK-03** | Service disruption during subscription upgrade | **MEDIUM** | Lost sales, customer churn | Introduce plan versioning with grandfathered subscription terms. |
| **RSK-04** | NBR audit penalties for non-compliant invoices | **HIGH** | Tax penalties, license revocation | Implement official Mushak 6.3 template and BIN validation. |
| **RSK-05** | Double charging during mobile MFS payment timeouts | **MEDIUM** | Customer disputes | Implement Redis-backed idempotency keys on checkout endpoints. |

---

## 47. TECHNICAL RECOMMENDATIONS

1. **Immediate Action:** Remove hardcoded credentials from seeders and rotate all administrative passwords.
2. **Immediate Action:** Apply Spatie `can:` middleware to all routes in `routes/web.php` to close the broken access control vulnerability.
3. **Architecture:** Unify financial accounts into the Chart of Accounts to establish true double-entry bookkeeping.
4. **Compliance:** Prioritize the development of Mushak 6.3 tax invoices to enable legal deployment in Bangladesh.
5. **Quality Assurance:** Mandate a minimum 80% automated test coverage for multi-tenant isolation and financial calculations before production launch.

---

## 48. FINAL ARCHITECTURE DECISIONS (ADR SUMMARY)

- **ADR-001 (Architecture Style):** Retain Modular Monolith on Laravel 11 + Inertia.js + Vue 3. Reject microservices due to unnecessary complexity and latency.
- **ADR-002 (Multi-Tenancy):** Retain shared database with row-level discriminator (`team_id`), but harden isolation via explicit `TenantContext` service and database session variables.
- **ADR-003 (Accounting):** Deprecate standalone `financial_accounts` table in favor of General Ledger asset sub-accounts. Automate journal postings via domain events.
- **ADR-004 (Plan Contracts):** Implement `plan_versions` to establish immutable contractual pricing and quota limits for subscribers.

---

## 49. OPEN QUESTIONS AND UNVERIFIED AREAS

1. **SMS Gateway Production Credentials:** Live testing observed mock/log mode for BulkSMSBD (`৳ 999.00 (Log/Dev Mode)`). Production API credentials and DLR webhook endpoints must be verified with the SMS vendor.
2. **SSLCommerz Production Merchant Account:** Live application is configured for sandbox mode. Production Store ID and Store Password must be validated.
3. **Historical Data Migration:** Prior to merging `financial_accounts` into `chart_of_accounts`, an ETL script must be authored to reconcile historical opening balances.

---

## 50. APPENDIX: ROUTES, DATABASE TABLES, AND IMPORTANT REFERENCES

### 50.1 Key Source Code References
- **Platform Control Plane:** `app/Http/Controllers/SuperAdminController.php`
- **Plan Management:** `app/Http/Controllers/AdminPlanController.php`
- **MFS Payment Approvals:** `app/Http/Controllers/AdminPaymentController.php`
- **Point of Sale Terminal:** `app/Http/Controllers/PosController.php`
- **Double-Entry Accounting Service:** `app/Services/AccountingService.php`
- **Subscription & Quota Service:** `app/Services/SubscriptionService.php`
- **Multi-Tenant Global Scope:** `app/Models/Scopes/TenantScope.php`
- **Multi-Tenant Model Trait:** `app/Traits/BelongsToTenant.php`
- **UI Application Shell:** `resources/js/Layouts/AppLayout.vue`
- **Route Definitions:** `routes/web.php`
- **Database Migrations:** `database/migrations/*.php` (56 files defining 62 tables)
"""
