# -*- coding: utf-8 -*-
"""
Sections 11 to 22 of ERP System Design Document
"""

CONTENT = r"""
---

## 11. AUTHENTICATION AND RBAC ANALYSIS

### 11.1 Authentication Mechanics
Authentication is built on Laravel Jetstream and Fortify with Laravel Sanctum:
- **Standard Credentials:** Email address and bcrypt/Argon2id password (`bcrypt_rounds = 12`).
- **Two-Factor Authentication (2FA):** TOTP QR-code generation and recovery codes implemented via Fortify (`2026_09_10_112044_add_two_factor_columns_to_users_table.php`).
- **WebAuthn / Passkeys:** Hardware security key and biometric passkey support (`passkeys` table, `2026_09_10_112045_create_passkeys_table.php`).
- **Phone OTP Verification:** Required for free trial accounts and password resets (`PhoneVerificationController.php`, `ForgotPasswordController.php`) using Bangladeshi 11-digit mobile numbers via BulkSMSBD.

### 11.2 Role-Based Access Control (RBAC) Architecture
- **Library:** Spatie Laravel-Permission (`v6.25`) configured with team multi-tenancy enabled (`config/permission.php`).
- **Standard Tenant Roles:**
  1. `owner`: Has all permissions across all domain boundaries.
  2. `admin`: Has operational and management permissions (excludes billing and team deletion).
  3. `staff`: Restricted to stock movements and sales order processing.
  4. `viewer`: Read-only access to reporting endpoints.
- **Critical RBAC Finding `[VERIFIED]`:**
  - Route definitions in `routes/web.php` use only `'auth:sanctum'`, `config('jetstream.auth_session')`, `'verified'`, and `'super_admin'` (for `/admin/*`).
  - No domain route is protected by `can:` or `role:` middleware (e.g. `middleware('can:manage-products')`).
  - Controllers do not execute `$this->authorize(...)` checks.
  - Role gating is performed solely in the Vue frontend via conditional DOM rendering (`v-if`). Consequently, a malicious or curious tenant user with `viewer` role can execute arbitrary POST/PUT/DELETE operations by issuing crafted HTTP requests to backend endpoints.

---

## 12. CURRENT DATABASE ARCHITECTURE

### 12.1 Schema Overview
The database comprises **62 active tables** partitioned logically into Platform, Tenancy, Identity, Catalog, Warehouse & Inventory, Sales & POS, Procurement, Service Workshop, Contracting & Projects, HRM & Payroll, Double-Entry Accounting, Single-Entry Financial Accounts, and System Infrastructure.

### 12.2 Structural Patterns
- **Primary Keys:** Auto-incrementing unsigned 64-bit integers (`bigIncrements('id')`).
- **Foreign Keys:** Enforced via `$table->foreignId('...')->constrained()->cascadeOnDelete()`.
- **Soft Deletes:** Applied to primary business entities (`products`, `customers`, `suppliers`, `warehouses`, `sales_orders`, `purchase_orders`, `projects`, `employees`, `financial_accounts`).
- **Financial Precision:** Currency fields are defined as `decimal(12, 2)` or `decimal(15, 2)` (e.g. `price_bdt`, `subtotal`, `total_amount`, `debit`, `credit`).

---

## 13. COMPLETE DATABASE TABLE INVENTORY

The following table catalogs all 62 active tables currently verified in the codebase:

| Table Name | Primary Key | Tenant Scope | Foreign Keys | Key Columns | Purpose / Domain | Status |
|---|---|---|---|---|---|---|
| `users` | `id` | Global | `current_team_id` -> `teams.id` | `name`, `email`, `phone`, `password`, `is_super_admin`, `phone_verified_at` | Platform & Tenant Users | Existing `[VERIFIED]` |
| `password_reset_tokens` | `email` | Global | None | `email`, `token`, `created_at` | Authentication | Existing `[VERIFIED]` |
| `sessions` | `id` | Global | `user_id` -> `users.id` | `ip_address`, `user_agent`, `payload`, `last_activity` | Session Storage | Existing `[VERIFIED]` |
| `cache` | `key` | Global | None | `key`, `value`, `expiration` | Cache Storage | Existing `[VERIFIED]` |
| `cache_locks` | `key` | Global | None | `key`, `owner`, `expiration` | Cache Locks | Existing `[VERIFIED]` |
| `jobs` | `id` | Global | None | `queue`, `payload`, `attempts`, `reserved_at`, `available_at` | Asynchronous Queue | Existing `[VERIFIED]` |
| `job_batches` | `id` | Global | None | `name`, `total_jobs`, `pending_jobs`, `failed_jobs`, `failed_job_ids` | Batch Processing | Existing `[VERIFIED]` |
| `failed_jobs` | `id` | Global | None | `uuid`, `connection`, `queue`, `payload`, `exception` | Failed Queue Audit | Existing `[VERIFIED]` |
| `passkeys` | `id` | Global | `user_id` -> `users.id` | `name`, `credential_id`, `data` | WebAuthn Passkeys | Existing `[VERIFIED]` |
| `personal_access_tokens` | `id` | Global | Polymorphic `tokenable` | `name`, `token`, `abilities`, `last_used_at`, `expires_at` | API Sanctum Tokens | Existing `[VERIFIED]` |
| `teams` | `id` | Global (Root) | `user_id` -> `users.id` | `name`, `legal_name`, `email`, `phone`, `status`, `currency_code`, `currency_symbol` | Tenant Organizations | Existing `[VERIFIED]` |
| `team_user` | `id` | Global | `team_id`, `user_id`, `warehouse_id` | `role` | Tenant User Pivot | Existing `[VERIFIED]` |
| `team_invitations` | `id` | Tenant | `team_id` -> `teams.id` | `email`, `role` | Team Invitations | Existing `[VERIFIED]` |
| `permissions` | `id` | Global | None | `name`, `guard_name` | Spatie Permissions | Existing `[VERIFIED]` |
| `roles` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `guard_name`, `team_id` | Spatie Roles | Existing `[VERIFIED]` |
| `model_has_permissions` | Composite | Global/Tenant | `permission_id`, `model_type`, `model_id` | Spatie Model Permissions | Existing `[VERIFIED]` |
| `model_has_roles` | Composite | Tenant | `role_id`, `model_type`, `model_id`, `team_id` | Spatie Model Roles | Existing `[VERIFIED]` |
| `role_has_permissions` | Composite | Global | `permission_id`, `role_id` | Spatie Role Permissions | Existing `[VERIFIED]` |
| `plans` | `id` | Global | None | `name`, `slug`, `price_bdt`, `annual_price_bdt`, `max_*`, `features` | Subscription Tiers | Existing `[VERIFIED]` |
| `tenant_subscriptions` | `id` | Tenant | `team_id`, `plan_id` | `status`, `billing_cycle`, `trial_ends_at`, `starts_at`, `ends_at` | Active Subscriptions | Existing `[VERIFIED]` |
| `subscription_payments` | `id` | Tenant | `team_id`, `plan_id`, `reviewed_by` | `payment_number`, `amount_bdt`, `payment_method`, `trx_id`, `status` | Manual TrxID Payments | Existing `[VERIFIED]` |
| `site_settings` | `id` | Global | None | `key`, `value`, `group` | Global Platform CMS | Existing `[VERIFIED]` |
| `warehouses` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `code`, `address`, `type`, `is_default`, `is_active` | Warehouse Hubs | Existing `[VERIFIED]` |
| `product_categories` | `id` | Tenant | `team_id` -> `teams.id`, `parent_id` | `name`, `slug`, `description` | Product Hierarchy | Existing `[VERIFIED]` |
| `brands` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `slug`, `description` | Product Brands | Existing `[VERIFIED]` |
| `products` | `id` | Tenant | `team_id`, `product_category_id`, `brand_id` | `name`, `sku`, `barcode`, `cost_price`, `selling_price`, `track_inventory` | Catalog Master SKUs | Existing `[VERIFIED]` |
| `product_variants` | `id` | Tenant | `team_id`, `product_id` | `name`, `sku`, `barcode`, `additional_cost`, `additional_price` | Variant Attributes | Existing `[VERIFIED]` |
| `product_serials` | `id` | Tenant | `team_id`, `product_id`, `warehouse_id`, `sales_order_id` | `serial_number`, `status`, `warranty_expires_at` | Serial/IMEI Tracking | Existing `[VERIFIED]` |
| `stock_levels` | `id` | Tenant | `team_id`, `warehouse_id`, `product_id` | `quantity`, `reserved_quantity`, `available_quantity` | Warehouse Quantities | Existing `[VERIFIED]` |
| `stock_movements` | `id` | Tenant | `team_id`, `product_id`, `warehouse_id`, `user_id` | `type`, `quantity`, `unit_cost`, `reference_type`, `reference_id` | Inventory Ledger | Existing `[VERIFIED]` |
| `suppliers` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `company_name`, `email`, `phone`, `opening_balance` | Vendor Directory | Existing `[VERIFIED]` |
| `purchase_orders` | `id` | Tenant | `team_id`, `supplier_id`, `warehouse_id` | `po_number`, `order_date`, `total_amount`, `paid_amount`, `due_amount`, `status` | Procurement Orders | Existing `[VERIFIED]` |
| `purchase_order_items` | `id` | Tenant | `team_id`, `purchase_order_id`, `product_id` | `quantity`, `unit_cost`, `subtotal`, `batch_number`, `expiry_date` | PO Line Items | Existing `[VERIFIED]` |
| `customers` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `phone`, `email`, `address`, `opening_balance` | Customer Directory | Existing `[VERIFIED]` |
| `sales_orders` | `id` | Tenant | `team_id`, `customer_id`, `warehouse_id` | `order_number`, `total_amount`, `paid_amount`, `tendered_amount`, `change_amount` | Commercial Sales | Existing `[VERIFIED]` |
| `sales_order_items` | `id` | Tenant | `team_id`, `sales_order_id`, `product_id` | `quantity`, `unit_price`, `subtotal`, `warranty_days` | Sales Line Items | Existing `[VERIFIED]` |
| `quotations` | `id` | Tenant | `team_id`, `customer_id` | `quotation_number`, `total_amount`, `valid_until`, `status` | Price Estimates | Existing `[VERIFIED]` |
| `quotation_items` | `id` | Tenant | `team_id`, `quotation_id`, `product_id` | `quantity`, `unit_price`, `subtotal` | Quotation Lines | Existing `[VERIFIED]` |
| `delivery_challans` | `id` | Tenant | `team_id`, `sales_order_id`, `warehouse_id` | `challan_number`, `delivery_date`, `courier_name`, `tracking_number` | Delivery Challans | Existing `[VERIFIED]` |
| `delivery_challan_items`| `id` | Tenant | `team_id`, `delivery_challan_id`, `product_id`| `quantity` | Challan Lines | Existing `[VERIFIED]` |
| `product_returns` | `id` | Tenant | `team_id`, `customer_id`, `supplier_id`, `sales_order_id` | `return_number`, `type`, `total_amount`, `status` | Sales & Purchase Returns | Existing `[VERIFIED]` |
| `product_return_items` | `id` | Tenant | `team_id`, `product_return_id`, `product_id`| `quantity`, `unit_price`, `subtotal`, `reason` | Return Items | Existing `[VERIFIED]` |
| `service_tickets` | `id` | Tenant | `team_id`, `customer_id`, `technician_id` | `ticket_number`, `device_name`, `issue_description`, `status`, `total_amount` | Repair Tickets | Existing `[VERIFIED]` |
| `service_ticket_items` | `id` | Tenant | `team_id`, `service_ticket_id`, `product_id` | `type`, `description`, `quantity`, `unit_price`, `subtotal` | Billable Parts/Labor | Existing `[VERIFIED]` |
| `clients` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `company_name`, `email`, `phone` | Project Clients | Existing `[VERIFIED]` |
| `projects` | `id` | Tenant | `team_id`, `client_id` | `name`, `code`, `contract_amount`, `total_cost`, `billed_amount`, `status`| Contracting Projects | Existing `[VERIFIED]` |
| `project_items` | `id` | Tenant | `team_id`, `project_id` | `title`, `unit`, `quantity`, `unit_cost`, `total_cost` | Project Deliverables | Existing `[VERIFIED]` |
| `cost_categories` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `description` | Project Cost Types | Existing `[VERIFIED]` |
| `project_costs` | `id` | Tenant | `team_id`, `project_id`, `cost_category_id` | `amount`, `cost_date`, `description` | Incurred Costs | Existing `[VERIFIED]` |
| `project_bills` | `id` | Tenant | `team_id`, `project_id` | `bill_number`, `bill_date`, `amount`, `paid_amount`, `status` | Progress Invoices | Existing `[VERIFIED]` |
| `employees` | `id` | Tenant | `team_id` -> `teams.id`, `user_id` | `name`, `designation`, `department`, `basic_salary`, `status` | Staff Records | Existing `[VERIFIED]` |
| `payrolls` | `id` | Tenant | `team_id`, `employee_id`, `financial_account_id` | `month`, `year`, `net_salary`, `status`, `paid_at` | Salary Disbursement | Existing `[VERIFIED]` |
| `expense_categories` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `description` | Operating Expense Types | Existing `[VERIFIED]` |
| `expenses` | `id` | Tenant | `team_id`, `expense_category_id`, `financial_account_id` | `amount`, `expense_date`, `recipient`, `notes` | Operating Expenses | Existing `[VERIFIED]` |
| `fiscal_years` | `id` | Tenant | `team_id` -> `teams.id` | `name`, `start_date`, `end_date`, `is_closed` | Accounting Periods | Existing `[VERIFIED]` |
| `chart_of_accounts` | `id` | Tenant | `team_id` -> `teams.id`, `parent_id` | `account_code`, `name`, `type`, `subtype`, `is_system` | Chart of Accounts (COA) | Existing `[VERIFIED]` |
| `journal_entries` | `id` | Tenant | `team_id`, `fiscal_year_id`, `created_by` | `entry_number`, `entry_date`, `narration`, `is_posted` | Double-Entry Vouchers | Existing `[VERIFIED]` |
| `journal_entry_lines` | `id` | Tenant | `team_id`, `journal_entry_id`, `chart_of_account_id` | `debit`, `credit`, `memo` | Balanced Voucher Lines | Existing `[VERIFIED]` |
| `bank_reconciliations` | `id` | Tenant | `team_id`, `chart_of_account_id` | `reconciliation_date`, `statement_balance`, `reconciled_balance`| Bank Reconciliation | Existing `[VERIFIED]` |
| `financial_accounts` | `id` | Tenant | `team_id` -> `teams.id`, `chart_of_account_id`| `name`, `type`, `account_number`, `current_balance`, `is_default` | Cash / Bank Balances | Existing `[VERIFIED]` |
| `financial_transactions`| `id` | Tenant | `team_id`, `financial_account_id` | `transaction_type`, `amount`, `balance_after`, `description` | Single-Entry Ledger | Existing `[VERIFIED]` |
| `activity_log` | `id` | Global/Tenant | `causer_id` -> `users.id` | `log_name`, `description`, `subject_type`, `subject_id`, `properties` | Spatie Activity Logs | Existing `[VERIFIED]` |

---

## 14. EXISTING ENTITY RELATIONSHIP DIAGRAM (CURRENT STATE)

```mermaid
erDiagram
    TEAMS ||--o{ USERS : "has members via team_user"
    TEAMS ||--o{ WAREHOUSES : "operates"
    TEAMS ||--o{ PRODUCTS : "owns catalog"
    TEAMS ||--o{ CUSTOMERS : "manages"
    TEAMS ||--o{ SUPPLIERS : "purchases from"
    TEAMS ||--o{ SALES_ORDERS : "issues"
    TEAMS ||--o{ PURCHASE_ORDERS : "orders"
    TEAMS ||--o{ FINANCIAL_ACCOUNTS : "holds cash/bank"
    TEAMS ||--o{ CHART_OF_ACCOUNTS : "maintains COA"
    TEAMS ||--o{ JOURNAL_ENTRIES : "records"
    TEAMS ||--o| TENANT_SUBSCRIPTIONS : "subscribes"

    PLANS ||--o{ TENANT_SUBSCRIPTIONS : "defines limits for"
    PLANS ||--o{ SUBSCRIPTION_PAYMENTS : "priced by"

    TENANT_SUBSCRIPTIONS ||--o{ SUBSCRIPTION_PAYMENTS : "settled via"

    PRODUCTS ||--o{ PRODUCT_VARIANTS : "has variants"
    PRODUCTS ||--o{ STOCK_LEVELS : "stored in"
    PRODUCTS ||--o{ STOCK_MOVEMENTS : "tracks changes"
    PRODUCTS ||--o{ PRODUCT_SERIALS : "tracks units"
    WAREHOUSES ||--o{ STOCK_LEVELS : "contains stock"
    WAREHOUSES ||--o{ STOCK_MOVEMENTS : "origin/destination"

    CUSTOMERS ||--o{ SALES_ORDERS : "places"
    SALES_ORDERS ||--|{ SALES_ORDER_ITEMS : "contains"
    PRODUCTS ||--o{ SALES_ORDER_ITEMS : "ordered as"
    SALES_ORDERS ||--o{ DELIVERY_CHALLANS : "fulfilled by"
    SALES_ORDERS ||--o{ FINANCIAL_TRANSACTIONS : "receives payment in"

    SUPPLIERS ||--o{ PURCHASE_ORDERS : "supplies"
    PURCHASE_ORDERS ||--|{ PURCHASE_ORDER_ITEMS : "contains"
    PRODUCTS ||--o{ PURCHASE_ORDER_ITEMS : "supplied as"
    PURCHASE_ORDERS ||--o{ FINANCIAL_TRANSACTIONS : "paid from"

    FINANCIAL_ACCOUNTS ||--o{ FINANCIAL_TRANSACTIONS : "logs single-entry"
    FINANCIAL_ACCOUNTS ||..o| CHART_OF_ACCOUNTS : "loosely linked (unposted)"

    CHART_OF_ACCOUNTS ||--o{ JOURNAL_ENTRY_LINES : "categorizes"
    JOURNAL_ENTRIES ||--|{ JOURNAL_ENTRY_LINES : "contains balanced lines"
```

---

## 15. BACKEND ARCHITECTURE ANALYSIS

### 15.1 Request Processing Pipeline
A request to the InoManage backend travels through the following architectural stages:
1. **HTTP Ingestion:** Nginx terminates TLS / proxies port 8080 to PHP-FPM / Laravel `public/index.php`.
2. **Global Middleware:** `SecurityHeaders`, `EncryptCookies`, `PreventRequestsDuringMaintenance`, `ValidatePostSize`, `TrimStrings`, `ConvertEmptyStringsToNull`.
3. **Routing & Authentication:** Matched against `routes/web.php`. Protected by `auth:sanctum` and Jetstream session cookies.
4. **Tenant Context Resolution:** `SetTenantContext` ensures `$user->current_team_id` is populated and calls `setPermissionsTeamId()`.
5. **Tenant Gate Enforcement:** `CheckTenantStatus` checks if the tenant team is suspended, expired, or pending phone OTP verification.
6. **Controller Invocation:** Dispatches to domain controller (e.g. `PosController`, `SalesOrderController`).
7. **Service Layer Execution:** Controller delegates domain logic to a dedicated service (`SalesOrderService`, `StockService`).
8. **Eloquent Model & Scope:** Models booting `BelongsToTenant` apply `TenantScope`, appending `WHERE team_id = ?`.
9. **Inertia Response Assembly:** Controller returns `Inertia::render('Page/Component', $props)` or JSON response. `HandleInertiaRequests` merges shared context (`auth`, `flash`, `site`, `subscription_notice`).

### 15.2 Code Quality & SOLID Evaluation
- **Strengths:** High degree of service abstraction (`SubscriptionService`, `AccountingService`, `StockService`). Database transactions (`DB::transaction`) are consistently wrapped around multi-table mutations.
- **Deficiencies:**
  - *Fat Controllers:* `SuperAdminController` (721 lines), `PosController` (525 lines), and `ReportController` (1,200+ lines) contain embedded query logic and inline formatting.
  - *Dual Financial Redundancy:* Direct violation of the Single Responsibility Principle; financial transactions update `financial_accounts` current balance imperatively rather than deriving balances from journal lines.

---

## 16. API ARCHITECTURE INVENTORY

The application operates primarily as an Inertia.js web application with select JSON API endpoints.

| HTTP Method | Route URI | Action / Controller | Auth Middleware | Expected Payload / Response |
|---|---|---|---|---|
| `GET` | `/admin` | `SuperAdminController@index` | `auth, verified, super_admin` | Inertia: metrics, paginated tenants, plans |
| `POST` | `/admin/tenants` | `SuperAdminController@storeTenant` | `auth, verified, super_admin` | JSON/Redirect: owner, organization, plan |
| `PUT` | `/admin/tenants/{team}` | `SuperAdminController@updateTenant` | `auth, verified, super_admin` | Redirect: company profile fields |
| `POST` | `/admin/tenants/{team}/toggle-status` | `SuperAdminController@toggleStatus` | `auth, verified, super_admin` | Redirect: toggle active/suspended |
| `POST` | `/admin/tenants/{team}/switch` | `SuperAdminController@switchToTenant` | `auth, verified, super_admin` | Redirect: sets current_team_id |
| `GET` | `/admin/plans` | `AdminPlanController@index` | `auth, verified, super_admin` | Inertia: plan list, subscriber metrics |
| `POST` | `/admin/plans` | `AdminPlanController@store` | `auth, verified, super_admin` | Redirect: name, price_bdt, quotas |
| `PUT` | `/admin/plans/{plan}` | `AdminPlanController@update` | `auth, verified, super_admin` | Redirect: updated quotas & prices |
| `DELETE` | `/admin/plans/{plan}` | `AdminPlanController@destroy` | `auth, verified, super_admin` | Redirect: checks active subscribers |
| `GET` | `/admin/payments` | `AdminPaymentController@index` | `auth, verified, super_admin` | Inertia: pending/approved TrxID payments |
| `POST` | `/admin/payments/{payment}/approve` | `AdminPaymentController@approve` | `auth, verified, super_admin` | Redirect: extends tenant subscription |
| `POST` | `/admin/payments/{payment}/reject` | `AdminPaymentController@reject` | `auth, verified, super_admin` | Redirect: records rejection reason |
| `POST` | `/pos/sale` | `PosController@store` | `auth, tenant_check` | JSON: items, warehouse, payment |
| `POST` | `/inventory/transfer` | `InventoryController@transfer` | `auth, tenant_check` | Redirect: source_wh, target_wh, items |
| `POST` | `/accounting/journal` | `JournalEntryController@store` | `auth, tenant_check` | Redirect: lines (debit/credit balanced) |
| `POST` | `/sslcommerz/ipn` | `SslCommerzPaymentController@ipn` | None (Public IPN Webhook) | JSON: automated gateway callback |

---

## 17. EXISTING BUSINESS WORKFLOWS

### 17.1 POS Retail Sale Flow
1. Cashier opens `/pos`. System loads active products, warehouses, and financial accounts.
2. Cashier scans barcode or searches SKU. System verifies item exists and selects fulfillment warehouse.
3. System verifies stock level in `stock_levels` table (`available_quantity >= needed`).
4. Cashier inputs tendered cash amount. Vue component computes change amount dynamically.
5. On checkout submission:
   - Atomic transaction creates `sales_orders` and `sales_order_items`.
   - `StockService::dispatchOrder()` creates `stock_movements` (type `'sale'`) and decrements `stock_levels.quantity` and `available_quantity`.
   - `FinancialAccount::deposit()` increments `current_balance` and logs `financial_transactions`.
   - Pos popup opens with thermal receipt preview (`/sales/{order}/pos`).

### 17.2 Subscription Payment Verification Flow
1. Tenant navigates to `/subscription`. Selects Starter, Professional, or Enterprise plan with Monthly or Yearly billing.
2. Tenant selects payment rail: bKash, Nagad, Rocket, or Bank Transfer.
3. System presents vendor merchant numbers (configured in `site_settings`).
4. Tenant transfers funds externally via mobile app and submits sender number and transaction ID (`TrxID`).
5. Record created in `subscription_payments` with status `'pending'`.
6. Super-Admin opens `/admin/payments`, verifies transaction in merchant portal, and clicks "Approve".
7. System transitions payment to `'approved'`, extends `tenant_subscriptions.ends_at` by 1 or 12 months, updates `teams.status` to `'active'`, and unlocks full quota limits.

---

## 18. SECURITY AUDIT & VULNERABILITY ASSESSMENT

A comprehensive security evaluation was performed based on source code auditing and existing penetration test findings (`report.md`):

| Finding ID | Severity | Category | Description & Impact | Verification Status |
|---|---|---|---|---|
| **SEC-001** | **CRITICAL** | Hardcoded Credentials | Shipped database seeder commits production super-admin password `hello@inoodex.com` / `hello@inoodex.com` with pre-verified phone OTP. Allowed full control plane takeover. | **VERIFIED** (`UserAndTenantSeeder.php#L26`) |
| **SEC-002** | **HIGH** | Broken Access Control (BAC) | Backend routes lack Spatie permission middleware (`can:` / `role:`). Staff and viewer roles can issue unauthorized mutations (e.g. deleting products, editing payroll). | **VERIFIED** (`routes/web.php`) |
| **SEC-003** | **HIGH** | Cross-Tenant Leakage in Queues | `TenantScope` relies on `Auth::check()`. Background queue workers and console commands run without auth session, bypassing tenant filtering on Eloquent models. | **VERIFIED** (`TenantScope.php#L17`) |
| **SEC-004** | **MEDIUM** | Query Builder Tenant Bypass | Raw queries using `DB::table(...)` in controllers bypass `TenantScope`. Multiple reports and aggregate counters manually filter `team_id`, creating risk of accidental omitted clauses. | **VERIFIED** (`SuperAdminController.php#L71`) |
| **SEC-005** | **MEDIUM** | In-Place Plan Mutation | Modifying a subscription plan immediately affects active subscribers' quota limits and terms without grandfathering or contract versioning. | **VERIFIED** (`AdminPlanController.php#L99`) |
| **SEC-006** | **LOW** | Missing CSRF on Fallback Assets | Public file fallback route (`/storage/{path}`) serves uploaded assets directly without tenant ownership verification. | **VERIFIED** (`routes/web.php#L402`) |

---

## 19. INFRASTRUCTURE & DEPLOYMENT ASSESSMENT

### 19.1 Current Deployed Topology
- **Host Node:** Ubuntu Linux host at `192.168.100.202` (internal) / Webuzo VPS (`162.0.214.157`).
- **Web Server:** Nginx 1.24.0 handling SSL termination and static asset caching.
- **Process Manager:** PHP-FPM 8.2 running under systemd.
- **Database:** Local MariaDB 10.4+ / MySQL 8.0 instance accessed over localhost socket.
- **File Storage:** Local disk filesystem (`storage/app/public`) symlinked to `public/storage`.

---

## 20. CURRENT SYSTEM STRENGTHS

1. **Broad Functional Coverage:** Rich domain implementation covering trading, retail, workshop repair tickets, project milestone contracting, and staff payroll in a single unified codebase.
2. **Robust Multi-Warehouse Inventory:** Strong real-time stock allocation model preventing negative overselling during POS checkout.
3. **Bangladeshi Commercial Alignment:** Native BDT currency semantics, dual MFS manual payment workflows, and local SMS gateway integration.
4. **Professional UI Execution:** Fast, modern SPA interface built with Vue 3 and Inertia.js, featuring responsive layouts and desktop sidebar pinning.

---

## 21. CURRENT SYSTEM WEAKNESSES

1. **Unenforced Backend Authorization:** Absolute reliance on frontend UI hiding for tenant-level permissions.
2. **Disconnected Accounting Subsystem:** Parallel single-entry cash tracking (`financial_accounts`) completely separated from double-entry General Ledger (`journal_entries`).
3. **Absence of NBR VAT Engine:** Zero automated support for mandatory Bangladesh National Board of Revenue VAT Mushak forms (6.3, 6.5, 6.7, 6.8).
4. **Fragile Tenant Isolation in Background Contexts:** Global query scope fails to bind in asynchronous CLI/queue contexts.

---

## 22. TECHNICAL DEBT AUDIT

1. **Hardcoded Financial Account Logic:** Controllers directly instantiate or fall back to arbitrary financial accounts when none is selected (`PurchaseOrderController.php#L120`, `SalesOrderController.php#L166`).
2. **Redundant Migration History:** Tables created and subsequently dropped in separate migrations (`recurring_invoices`, `ta_da_claims`), leaving orphaned artifacts in migration metadata.
3. **Lack of Unit & Integration Test Suite:** Only basic skeleton test files exist (`tests/Feature/ExampleTest.php`); zero automated test coverage exists for tenant isolation, stock reservation, or subscription paywalls.
"""
