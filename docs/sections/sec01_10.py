# -*- coding: utf-8 -*-
"""
Sections 1 to 10 of ERP System Design Document
"""

CONTENT = r"""# INOMANAGE CLOUD ERP: COMPLETE REVERSE ENGINEERING, TECHNICAL AUDIT & ENTERPRISE SYSTEM ARCHITECTURE DESIGN

> **Document Type:** Master Architectural Blueprint & System Design Document (TDD / SAD)  
> **Target System:** InoManage Cloud Multi-Tenant SaaS ERP (`v1.2-rc`)  
> **Document Status:** Complete / Definitive  
> **Target Audience:** Engineering Leads, Senior Full-Stack Developers, DevOps/SREs, Enterprise ERP Architects  
> **Classification:** Internal Engineering & Architecture Blueprint  
> **Last Verified Date:** November 18, 2026  

---


> [!IMPORTANT]
> **PROJECT INDEPENDENCE & ISOLATION MANDATE:**  
> This software product is an entirely independent, standalone SaaS ERP system (discovered codebase identity: **InoManage Cloud ERP** / Saas_inventory). It has zero architectural affiliation, shared code, or inherited assumptions from any external, legacy, or unrelated commercial projects (such as *KC Retail ERP*, *RetailCore*, or *POS_ERP_Multi_Tenant*). Every architectural finding, database schema diagram, workflow definition, and system recommendation in this document is derived strictly, exclusively, and empirically from inspection of the repository located at D:\\Project\\Saas_inventory and the live runtime instance at http://192.168.100.202:8080.

---

## 1. EXECUTIVE SUMMARY

### 1.1 Engagement Context & Objectives
This technical audit and architecture blueprint is the result of an exhaustive reverse-engineering investigation of **InoManage**, a multi-tenant Software-as-a-Service (SaaS) Enterprise Resource Planning (ERP) platform developed for trading, retail, wholesale, computer hardware vendors, workshop services, and project contractors. The system is designed primarily for the Bangladeshi commercial market, incorporating BDT currency semantics, manual Mobile Financial Services (MFS: bKash, Nagad, Rocket) workflow verifications, SSLCommerz gateway integration, and local SMS OTP gateways.

The investigation encompassed both white-box source code auditing (PHP 8.2, Laravel 11.57.1, Vue 3.5, Inertia.js 3.9, Tailwind CSS 3.4, Vite 5.0) and black-box live runtime verification against the deployed host at `http://192.168.100.202:8080`. Both administrative and tenant operational personas were authenticated, explored, and validated.

### 1.2 Summary of Architectural Findings
1. **Multi-Tenancy Foundation `[VERIFIED]`:** The application utilizes a single shared MySQL/MariaDB database with row-level discriminator tenancy (`team_id`). Tenant isolation is enforced in Eloquent models via a global scope (`TenantScope`) driven by `Auth::user()->current_team_id`. However, critical isolation gaps exist: unauthenticated execution contexts (queues, console commands, webhook handlers) do not enforce `TenantScope`, and raw database queries (`DB::table(...)`) bypass model scopes entirely, presenting high cross-tenant data leakage risks.
2. **Access Control & RBAC Vulnerabilities `[VERIFIED]`:** While Spatie Laravel-Permissions (`v6.25`) is installed and roles (`owner`, `admin`, `staff`, `viewer`) are seeded per tenant team, backend route definitions in `routes/web.php` and controller actions lack authorization checks (`can:`, `Gate::authorize`, `$this->authorize(...)`). Authorization is predominantly enforced cosmetically in the Vue 3 frontend, allowing any authenticated user within a tenant to execute administrative and financial operations via direct HTTP calls.
3. **Dual Disconnected Financial Architecture `[VERIFIED]`:** The codebase contains two parallel, unintegrated financial subsystems: (a) a single-entry cash/bank tracking system (`financial_accounts`, `financial_transactions`), and (b) a formal double-entry general ledger (`chart_of_accounts`, `journal_entries`, `journal_entry_lines`). Operational transactions (POS counter sales, sales orders, purchase orders, customer due collections, expenses, payroll) write solely to `financial_accounts` and do not post balanced journal entries to the General Ledger. Consequently, formal accounting reports (Balance Sheet, P&L, Trial Balance) are decoupled from daily commercial operations.
4. **Subscription & Quota Management `[VERIFIED]`:** A full Bangladeshi BDT subscription engine exists (`plans`, `tenant_subscriptions`, `subscription_payments`) with 4 distinct tiers: Free Trial (3 days), Starter (৳999/mo), Professional (৳2,499/mo), and Enterprise (৳4,999/mo). Quotas for products, warehouses, users, quotations, sales orders, purchase orders, projects, and employees are enforced in application services (`SubscriptionService`), but plan changes lack historical versioning, immediately altering quota references for active subscribers.
5. **Bangladesh Regulatory Compliance Status `[VERIFIED]`:** The platform features localized BDT formatting, Bangladeshi phone number validation (`01[3-9]\\d{8}`), SMS OTP verification via BulkSMSBD, and MFS payment rails. However, statutory National Board of Revenue (NBR) VAT compliance—specifically automated generation of Mushak 6.3 (Tax Invoice), Mushak 6.5 (Transfer Challan), Mushak 6.6 (Withholding Certificate), Mushak 6.7/6.8 (Credit/Debit Notes), and Mushak 9.1 VAT Return registers—is completely absent (`[VERIFIED: 0 occurrences in source]`).

---

## 2. PROJECT OVERVIEW

### 2.1 System Purpose
InoManage is designed to serve as an all-in-one cloud inventory, point of sale, procurement, project billing, and service management system for small to medium enterprises (SMEs) and multi-branch retail/distribution businesses.

### 2.2 Core Business Domains
- **SaaS Platform Control Plane:** Super-admin management of tenant organizations, plan definitions, quota allocations, subscription payment approvals, global site settings, and audit logs.
- **Retail & Point of Sale (POS):** Fast barcode-driven counter checkout, multi-warehouse stock reservation, cash tendering with change calculation, thermal receipt printing, and customer ledger tracking.
- **Inventory & Supply Chain:** Multi-hub warehouse topology, serial/IMEI tracking, warranty lifecycle management, inter-warehouse stock transfers, physical inventory adjustments, and stock valuation (FIFO / Weighted Average).
- **Procurement & Vendor Relations:** Purchase orders, goods receipt verification, supplier payment vouchers, supplier return debits, and vendor due ledgers.
- **Service & Workshop Management:** Job ticketing for device repairs, warranty RMA verification, technician task routing, billable parts utilization, service tokens, and delivery invoicing.
- **Contracting & Project Costing:** Project budgeting, cost category breakdown (materials, labor, logistics), progress billing, milestone invoicing, and profit tracking.
- **HRM & Payroll:** Employee directory, department hierarchy, monthly payroll sheet generation with allowance/deduction breakdowns, and operational expense disbursement.
- **Finance & Accounting:** Cash/bank account balances, journal vouchers, chart of accounts hierarchy, general ledger, and financial statements.

---

## 3. EXISTING APPLICATION OVERVIEW

### 3.1 Deployment & Runtime Verification
Live runtime testing confirmed the active deployment of InoManage:
- **Application URL:** `http://192.168.100.202:8080`
- **Platform Control Plane:** `http://192.168.100.202:8080/admin`
- **Plan Management Surface:** `http://192.168.100.202:8080/admin/plans`
- **Web Server:** Nginx 1.24.0 (Ubuntu) with HTTP/1.1 chunked transfer encoding
- **Application Framework:** Laravel 11.57.1 / PHP 8.2.x
- **Frontend Engine:** Single-Page Application (SPA) driven by Inertia.js 3.9.1, Vue 3.5.43, Tailwind CSS 3.4.0, and Ziggy 2.6.5

### 3.2 Operating Personas & Access Points
The live application exposes three fundamental operating personas:
1. **Platform Super-Administrator:**
   - Seeded Account: `hello@inoodex.com` `[VERIFIED]`
   - Scope: Platform-wide control plane (`/admin`, `/admin/plans`, `/admin/payments`, `/admin/site-settings`).
   - Capabilities: Tenant lifecycle provisioning, subscription overrides, plan quota pricing modifications, MFS payment approval/rejection, impersonation into any tenant workspace.
2. **Tenant Owner / Administrator:**
   - Seeded Account: `admin@inoodex.com` (Demo Store BD Ltd.) `[VERIFIED]`
   - Scope: Tenant workspace (`/dashboard`, `/pos`, `/products`, `/sales`, `/purchases`, `/accounting`, etc.).
   - Capabilities: Organization settings, employee management, warehouse management, commercial transactions.
3. **Tenant Staff / Viewer:**
   - Scope: Restricted operational role inside a tenant workspace. (Intended to be restricted to POS counter or inventory viewing, though backend checks are currently unenforced).

---

## 4. TECHNOLOGY STACK AUDIT

The technology stack was verified directly from `composer.json`, `package.json`, environment configurations, and HTTP headers:

| Layer | Technology | Version | Purpose / Evidence |
|---|---|---|---|
| **Programming Language** | PHP | `^8.2` | Core backend runtime (`composer.json#L8`) |
| **Backend Framework** | Laravel Framework | `11.0` (11.57.1) | Application core framework (`composer.json#L11`) |
| **Application Architecture** | Monolithic SPA via Inertia.js | `3.5` (Laravel) / `3.9.1` (Vue) | Inertia protocol connects Laravel backend to Vue views (`composer.json#L10`) |
| **Auth & Scaffolding** | Laravel Jetstream + Fortify | `5.5` | Authentication, 2FA, session management, personal teams (`composer.json#L12`) |
| **API Token Engine** | Laravel Sanctum | `4.0` | Stateful SPA cookie authentication & API tokens (`composer.json#L13`) |
| **Authorization / RBAC** | Spatie Laravel-Permission | `6.25` | Team-scoped roles and permissions (`composer.json#L19`) |
| **Audit Logging** | Spatie Activitylog | `4.12` | Model activity and system event tracking (`composer.json#L18`) |
| **PDF Generation** | carlos-meneses/laravel-mpdf | `^2.1` (mPDF) | Server-side invoice, challan, and ledger rendering (`composer.json#L9`) |
| **Excel Export/Import** | Maatwebsite/Laravel-Excel | `^3.1` (PhpSpreadsheet) | Bulk catalog data import/export (`composer.json#L16`) |
| **Barcode Engine** | Picqer PHP Barcode Generator | `^3.3` | Code128 / EAN13 SVG barcode label rendering (`composer.json#L17`) |
| **Frontend Framework** | Vue.js 3 | `3.5.43` | Composition API reactive SPA components (`package.json#L29`) |
| **Build Tooling** | Vite | `5.0` | ES module bundler and frontend build system (`package.json#L16`) |
| **CSS Framework** | Tailwind CSS | `3.4.0` | Utility-first responsive styling (`package.json#L15`) |
| **Iconography** | Lucide Vue Next | `1.0.0` | UI iconography (`package.json#L28`) |
| **Charts & Analytics** | ApexCharts + Vue3-ApexCharts | `8.3.1` / `1.11.3` | Financial, sales, and inventory dashboard charts (`package.json#L25,30`) |
| **Camera Barcode Scan**| html5-qrcode | `2.3.8` | Mobile/webcam barcode scanning in POS (`package.json#L27`) |
| **Rich Text Editor** | Tiptap Vue 3 | `3.33.0` | Invoice terms, legal notes, service descriptions (`package.json#L20-23`) |
| **Client-Side Routing**| Tighten Ziggy | `2.6.5` | Blade route compilation for JavaScript (`package.json#L31`) |
| **Database Engine** | MySQL / MariaDB | 8.0+ / 10.4+ | Relational persistence (`config/database.php`) |
| **Cache & Sessions** | Database / Redis | Laravel standard | Database-driven cache and session drivers |

---

## 5. REPOSITORY STRUCTURE INVENTORY

The repository follows standard Laravel 11 structure with domain-organized Vue 3 pages:

```
d:\Project\Saas_inventory
├── app/
│   ├── Actions/                  # Jetstream & Fortify user/team lifecycle actions
│   │   ├── Fortify/              # Password resets, user profile updates, 2FA
│   │   └── Jetstream/            # Team creation, membership, deletion actions
│   ├── Console/Commands/         # Scheduled and maintenance artisan commands
│   ├── Exceptions/               # Custom domain exceptions (Stock, Payment)
│   ├── Exports/                  # Maatwebsite Excel export definitions
│   ├── Helpers/                  # Global formatting and helper utilities
│   ├── Http/
│   │   ├── Controllers/          # 39 HTTP & Inertia Controllers
│   │   │   ├── AdminPlanController.php          # Plan management
│   │   │   ├── AdminPaymentController.php       # MFS payment approvals
│   │   │   ├── SuperAdminController.php         # Tenant provisioning & metrics
│   │   │   ├── PosController.php                # Retail checkout terminal
│   │   │   ├── SalesOrderController.php         # Sales & invoices
│   │   │   ├── PurchaseOrderController.php      # Procurement & receiving
│   │   │   ├── ChartOfAccountController.php     # COA management
│   │   │   ├── JournalEntryController.php       # Double-entry vouchers
│   │   │   ├── FinancialReportController.php    # Trial balance, P&L, balance sheet
│   │   │   ├── InventoryController.php          # Stock transfers & adjustments
│   │   │   ├── ServiceTicketController.php      # Repair & workshop jobs
│   │   │   ├── ProjectController.php            # Contracting & billing
│   │   │   ├── EmployeeController.php           # Staff directory
│   │   │   ├── PayrollController.php            # Salary disbursements
│   │   │   └── SslCommerzPaymentController.php  # Automated gateway callbacks
│   │   └── Middleware/           # 5 Custom Application Middleware
│   │       ├── CheckTenantStatus.php            # Paywall, suspension & OTP gates
│   │       ├── EnsureSuperAdmin.php             # Super-admin access enforcement
│   │       ├── HandleInertiaRequests.php        # Inertia shared props & auth context
│   │       ├── SecurityHeaders.php              # HTTP response security headers
│   │       └── SetTenantContext.php             # Spatie permission & team context sync
│   ├── Models/                   # 48 Eloquent Models
│   │   ├── Scopes/TenantScope.php# Global multi-tenant query filter
│   │   ├── Plan.php              # Subscription tier definition
│   │   ├── TenantSubscription.php# Team subscription binding
│   │   ├── SubscriptionPayment.php # Payment transaction records
│   │   ├── SalesOrder.php        # Commercial sales invoices
│   │   ├── PurchaseOrder.php     # Procurement orders
│   │   ├── JournalEntry.php      # Double-entry accounting vouchers
│   │   ├── FinancialAccount.php  # Bank & cash balances
│   │   └── Product.php           # Inventory SKUs
│   ├── Services/                 # 13 Dedicated Domain Services
│   │   ├── SubscriptionService.php   # Quota checks, payment transitions
│   │   ├── AccountingService.php     # Double-entry engine & reports
│   │   ├── StockService.php          # Inventory deduction, transfer, valuation
│   │   ├── SalesOrderService.php     # Order state machine & reservation
│   │   ├── PurchaseOrderService.php  # Procurement receiving workflow
│   │   ├── HrService.php             # Payroll calculations & salary slips
│   │   ├── ProjectService.php        # Project cost & billing logic
│   │   ├── ServiceTicketService.php  # Workshop repair tracking
│   │   ├── FinancialAccountService.php # Cash/bank deposits & withdrawals
│   │   └── SmsOtpService.php         # BulkSMSBD gateway driver
│   └── Traits/
│       └── BelongsToTenant.php   # Automatic team_id injection & TenantScope boot
├── config/                       # Application configuration files
├── database/
│   ├── migrations/               # 56 Migration files defining 62 active tables
│   └── seeders/                  # 20 Database seeders (Plans, Roles, COA)
├── resources/
│   ├── js/
│   │   ├── Components/           # Modals, Datatables, Badges, Search bars
│   │   ├── Layouts/AppLayout.vue # Unified enterprise shell (116 KB)
│   │   └── Pages/                # 23 Subdirectories with Vue 3 Inertia views
│   │       ├── Admin/            # Super-admin dashboard, plans, payments
│   │       ├── Accounting/       # General ledger, trial balance, P&L views
│   │       ├── POS/              # Counter terminal interface
│   │       ├── Products/         # Catalog & variant views
│   │       └── Sales/            # Order and invoice management
│   └── views/pdf/                # 18 mPDF Blade print templates
└── routes/
    ├── api.php                   # Stateless API endpoints
    └── web.php                   # Web and Inertia route definitions (30 KB)
```

---

## 6. EXISTING MODULE INVENTORY

Every functional module was evaluated across backend controllers, models, database tables, and frontend Vue 3 views.

| Module | Primary Controller | Primary Model(s) | Primary View(s) | Implementation Status | Evidence / Notes |
|---|---|---|---|---|---|
| **Platform Admin** | `SuperAdminController` | `Team`, `User` | `Admin/Dashboard.vue` | **Fully Implemented** | Tenant CRUD, suspension toggle, impersonation, real-time counters. |
| **Plan Management**| `AdminPlanController` | `Plan` | `Admin/Plans/Index.vue` | **Fully Implemented** | Tier creation, feature flags, monthly/yearly pricing, quota limits. |
| **Payment Approvals**| `AdminPaymentController`| `SubscriptionPayment` | `Admin/Payments/Index.vue`| **Fully Implemented** | TrxID verification for bKash, Nagad, Rocket, Bank transfer. |
| **Site Settings** | `SuperAdminController` | `SiteSetting` | `Admin/SiteSettings.vue` | **Fully Implemented** | Brand logo, favicon, hero text, SEO metadata, landing page CMS. |
| **POS Terminal** | `PosController` | `SalesOrder`, `StockLevel` | `POS/Index.vue` | **Fully Implemented** | Fast SKU search, barcode scanning, stock reservation, receipt popup. |
| **Product Catalog**| `ProductController` | `Product`, `ProductVariant`| `Products/Index.vue` | **Fully Implemented** | Variants, serial tracking, barcodes, low-stock thresholds. |
| **Inventory & Stock**| `InventoryController` | `StockMovement`, `Warehouse`| `Inventory/Index.vue` | **Fully Implemented** | Stock adjustments, inter-hub transfers, stock valuation. |
| **Warehouses** | `WarehouseController`| `Warehouse` | `Warehouses/Index.vue` | **Fully Implemented** | Multi-warehouse hubs, default hub designation, capacity tracking. |
| **Sales & Orders** | `SalesOrderController`| `SalesOrder`, `SalesOrderItem`| `Sales/Index.vue` | **Fully Implemented** | Quotation to invoice conversion, payment tracking, mPDF invoice. |
| **Quotations** | `QuotationController` | `Quotation`, `QuotationItem`| `Quotations/Index.vue` | **Fully Implemented** | Price estimates, expiry dates, conversion to confirmed sales. |
| **Delivery Challans**| `DeliveryChallanController`| `DeliveryChallan` | `Challans/Index.vue` | **Fully Implemented** | Transport challans, carrier tracking, dispatch verification. |
| **Customer Dues** | `CustomerDueController` | `Customer`, `SalesOrder` | `Dues/Index.vue` | **Fully Implemented** | Outstanding receivables, ledger collection, partial receipts. |
| **Procurement (PO)**| `PurchaseOrderController`| `PurchaseOrder` | `Purchases/Index.vue` | **Fully Implemented** | Supplier orders, batch numbering, goods receiving, cost updates. |
| **Supplier Dues** | `VendorDueController` | `Supplier`, `PurchaseOrder` | `VendorDues/Index.vue` | **Fully Implemented** | Accounts payable tracking, vendor payment vouchers. |
| **Returns & Credits**| `ProductReturnController`| `ProductReturn` | `Returns/Index.vue` | **Fully Implemented** | Customer sales returns, supplier debit notes, inventory restock. |
| **Service Workshop**| `ServiceTicketController`| `ServiceTicket` | `Services/Index.vue` | **Fully Implemented** | Repair tickets, technician assignment, billable spare parts. |
| **Project Costing**| `ProjectController` | `Project`, `ProjectCost` | `Projects/Index.vue` | **Fully Implemented** | Milestone budgeting, expense tracking, client progress billing. |
| **HR & Payroll** | `EmployeeController`, `PayrollController` | `Employee`, `Payroll` | `HR/Employees.vue`, `HR/Payroll.vue`| **Fully Implemented** | Staff directory, basic salaries, allowances, deductions, pay slips. |
| **Financial Accounts**| `FinancialAccountController`| `FinancialAccount` | `Settings/FinancialAccounts.vue` | **Fully Implemented** | Bank and MFS balances, deposit/withdrawal ledger. |
| **General Ledger** | `JournalEntryController`| `JournalEntry`, `JournalEntryLine`| `Accounting/Journal/Index.vue`| **Partially Implemented**| Manual journal posting works; automatic postings from Sales/PO absent. |
| **Financial Reports**| `FinancialReportController`| `ChartOfAccount` | `Accounting/Reports/*.vue` | **Partially Implemented**| Generates P&L, Balance Sheet, Trial Balance from manual entries only. |
| **Bank Reconciliation**| `FinancialReportController`| `BankReconciliation` | `Accounting/Reconciliation.vue`| **Partially Implemented**| Simple statement match; automated bank feed sync absent. |
| **NBR VAT (Mushak)**| None | None | None | **Not Implemented** | No Mushak 6.3, 6.5, 6.7, 6.8 or 9.1 return generation exists. |

---

## 7. UI/UX AUDIT

### 7.1 Architecture & Shell (`AppLayout.vue`)
The UI shell is implemented in `resources/js/Layouts/AppLayout.vue` (116,922 bytes). It provides:
- **Dual Personality Sidebar:** Automatically switches between Super-Admin navigation (Platform, Plans, Payments, Site Settings) and Tenant Workspace navigation depending on user role and impersonation state (`showTenantSidebar` computed property).
- **Responsive Drawer:** Collapsible sidebar supporting expanded state (256px / `w-64`), icon-only collapsed state (72px / `w-[72px]`), and mobile slide-over drawer with backdrop overlay.
- **Header Control Bar:** Global search trigger, quick-action create menu, workspace tenant switcher dropdown, notification bell, and user avatar profile dropdown.
- **Paywall & Trial Banner:** Sticky top banner warning tenants of expiring subscriptions (`subscription_notice` shared prop) with one-click renewal redirection.

### 7.2 Design System & Styling
- **Color Palette:** Enterprise slate palette (`bg-slate-50`, `bg-slate-900` sidebar), with primary indigo accents (`indigo-600`), emerald financial status badges (`emerald-600`), and amber warning indicators.
- **Typography:** Google Font *Plus Jakarta Sans* applied globally across layouts and PDF templates.
- **Component Consistency:** Form inputs (`resources/js/Components/TextInput.vue`), primary buttons (`PrimaryButton.vue`), modals (`Modal.vue`, `DialogModal.vue`), and badges follow consistent Tailwind utility tokens.

### 7.3 Data Table Standards
- All primary resource index pages utilize server-side pagination (10 to 15 records per page) with reactive search debounce, status dropdown filtering, and date range filters.
- Bulk selection actions are supported in Products and Sales for printing barcode labels or exporting to Excel.

---

## 8. PLATFORM MANAGEMENT ANALYSIS

The platform control plane is managed by `SuperAdminController.php` (`app/Http/Controllers/SuperAdminController.php`, 721 lines):

### 8.1 Super-Admin Capabilities
1. **Tenant Overview & Filtering (`index`):**
   - Queries `Team` without global scopes (`Team::withoutGlobalScopes()`).
   - Computes aggregated platform metrics: Total Tenants, Active Tenants, Suspended Tenants, Total Non-Admin Users, Total Products, Total Sales Orders.
   - Per-tenant metrics computed via optimized direct DB queries (`productsCounts`, `warehouseCounts`, `salesCounts`).
2. **Tenant Provisioning (`storeTenant`):**
   - Single atomic transaction: Creates owner `User`, provisions `Team`, calls `RolesAndPermissionsSeeder::seedRolesForTeam()`, assigns `owner` role, creates default `Warehouse` ('WH-MAIN'), seeds default Chart of Accounts via `AccountingService::ensureDefaultAccounts()`, and creates `TenantSubscription`.
3. **Tenant Lifecycle Control:**
   - **Suspension (`toggleStatus`):** Toggles `status` between `'active'` and `'suspended'`. Immediately enforced by `CheckTenantStatus` middleware to lock out tenant users.
   - **Workspace Impersonation (`switchToTenant`):** Updates super-admin's `current_team_id` to target team and sets `session('impersonated_tenant_id')`. Allows super-admin to view and debug tenant workspaces directly.
   - **Tenant Deletion (`destroyTenant`):** Executes `$team->delete()`. All child tables with foreign key `cascadeOnDelete()` are automatically wiped.

---

## 9. DETAILED PLAN MANAGEMENT ANALYSIS

Investigation of `http://192.168.100.202:8080/admin/plans` and underlying code (`AdminPlanController.php`, `Plan.php`, `SubscriptionService.php`):

### 9.1 Plan Structure & Storage
Plans are stored in the `plans` table (`2026_10_14_140000_create_bangladeshi_subscriptions_and_billing_tables.php` and `2026_11_14_115849_add_quota_columns_to_plans_table.php`).
- **Default Presets (`SubscriptionService::DEFAULT_PLANS`):**
  1. *Free Trial:* ৳0.00 / 3 days / 100 products, 1 warehouse, 2 users, 20 quotations, 50 sales, 20 POs.
  2. *Starter:* ৳999/mo (৳9,990/yr) / 500 products, 1 warehouse, 3 users, 100 quotations, 500 sales, 200 POs.
  3. *Professional:* ৳2,499/mo (৳24,990/yr) / 5,000 products, 3 warehouses, 10 users, 1,000 quotations, 5,000 sales, 2,000 POs, full accounting & workshop.
  4. *Enterprise:* ৳4,999/mo (৳49,990/yr) / Unlimited (-1) across all resources.

### 9.2 Critical Workflow Analysis
- **Plan Modifications (`update`):** Modifying a plan updates the record in-place. Because `TenantSubscription` references `plan_id` without versioning, changing quotas or prices immediately affects all current subscribers upon their next quota check.
- **Plan Deletion (`destroy`):** Hard delete blocked if `$plan->subscriptions()->count() > 0`. If subscriptions are zero, row is deleted. No soft deletes are enabled on `Plan`.
- **Feature Flag Enforcement:** Plan features are stored as a JSON column (`features`). Feature access is checked in views and controllers via boolean properties (e.g. `'accounting' => true`, `'service_workshop' => true`).
- **Quota Checking Engine:** Implemented in `SubscriptionService::canAddResource()`. Compares real-time table counts (`Product::where('team_id', $team->id)->count()`) against `plan->max_*` columns. Unlimited is represented by `-1`.

---

## 10. TENANT MANAGEMENT & MULTI-TENANCY STRATEGY

### 10.1 Tenancy Strategy Evaluation
The application implements **Shared Database with Shared Tables and Discriminator Column (`team_id`)**:
- Every tenant organization is represented as a `Team` record.
- Every tenant-scoped business record contains an unsigned integer column `team_id` referencing `teams.id` with `cascadeOnDelete()`.

### 10.2 Tenant Resolution & Context Flow
1. **Request Ingestion:** Request passes through `SetTenantContext` middleware.
2. **Context Assignment:** If user has no `current_team_id`, switches to `user->allTeams()->first()`.
3. **Spatie Context Synchronization:** Calls `setPermissionsTeamId($user->current_team_id)`.
4. **Model Scoping:** Models using `BelongsToTenant` trait register `TenantScope`, appending `WHERE team_id = ?` to Eloquent queries.

### 10.3 Identified Architectural Limitations
- **Background Execution Leakage:** `TenantScope` relies on `Auth::check()`. Background queue workers (`php artisan queue:work`) executing jobs without an active HTTP session run with `Auth::check() == false`, causing `TenantScope` to be omitted and potentially exposing all tenants' data.
- **Query Builder Bypass:** Direct calls to `DB::table('...')` (frequently used in `SuperAdminController`, `ReportController`, and `InventoryController`) bypass Eloquent global scopes, requiring manual `where('team_id', $teamId)` clauses that are vulnerable to developer oversight.
"""
