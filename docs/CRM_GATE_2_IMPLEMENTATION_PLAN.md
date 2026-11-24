# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
# STAGE 2 CRM — GATE 2 CONTROLLED IMPLEMENTATION PLAN
## Core CRM Architecture, Module Design, and Implementation Specification

**Project:** InoManage Cloud Multi-Tenant SaaS ERP  
**Repository:** `D:\Project\Saas_inventory`  
**Current Branch:** `crm/gate-1-schema`  
**Accepted Gate 1 HEAD:** `a0cd9941ffcfae6e603e0e31c8a5674916442dd4`  
*(Parent: `fb129f7a94b5384a4ef4b208df6f604c1ac6b4b5`; Gate 0 Base: `546d423fb94ca59bcdb5d8085c698b5e76754092`; Gate 5 Baseline: `402b898d68f25c9084cac0b4e7ef612e0402b06c`)*  
**Document Status:** DRAFT FOR PROJECT OWNER APPROVAL (REVISION 2.2 — REFINED)  
**Implementation Authorization:** NOT AUTHORIZED — PLANNING ONLY  

---

## 1. EXECUTIVE SUMMARY & ARCHITECTURAL SCOPE

This document provides the exhaustive, production-grade implementation architecture for **Stage 2 CRM — Gate 2 (Core CRM First)** of the InoManage Cloud Multi-Tenant SaaS ERP platform.

### 1.1 Scope Boundaries (Core CRM First)
Gate 2 delivers the operational core of the CRM subsystem, integrating seamlessly with the existing multi-tenant ERP platform without disrupting existing accounting, inventory, or billing modules:
1. **WP1 — Leads Management:** Inbound capture, qualification, scoring, assignment, branch routing, activity timelines, and atomic, transaction-safe conversion into canonical ERP customers.
2. **WP2 — B2B Accounts & B2C Customers:** Cohesive account and customer management leveraging the canonical ERP `customers` master, enriched with B2B stakeholders and multi-contact relationship management via `crm_contacts`. Employs an intelligent compound classification heuristic (Track A) while presenting a separate schema change proposal (Track B) for owner consideration.
3. **WP3 — Sales Pipelines & Deals:** Multi-pipeline management, configurable stage funnels, Kanban visualization, deal products (`crm_deal_items`), quotation linkages (`quotation_id`), and won/lost tracking.
4. **WP4 — Activities & Tasks:** Unified scheduling and logging for calls, meetings, follow-up tasks, and notes, polymorphically attached to leads, deals, customers, and contacts.
5. **WP5 — Customer 360:** A consolidated single-pane-of-glass profile unifying contact details, stakeholder trees, related leads, deal histories, quotation and sales order summaries, activity audit trails, and authoritative, read-only financial balances directly integrated with ERP ledger services (`CustomerDueController` / `SalesOrderService`), strictly preventing opening balance double-counting.
6. **WP6 — CRM Dashboard & Reporting:** Real-time KPI summaries, conversion funnels, pipeline stage valuation, representative performance metrics, and overdue activity tracking, strictly scoped by tenant and branch permissions.

### 1.2 Strict Invariants & Prohibitions
To preserve architectural integrity and avoid regressions:
- **Zero Duplicate Masters:** Existing ERP `customers` remains the sole, canonical customer entity. No secondary CRM customer table shall be created.
- **Untouched Project Costing:** The `clients` table (dedicated to Project Costing) remains completely unmodified and isolated from CRM operations.
- **Authoritative Ledgers:** Financial balances, dues, and receivables must be computed directly from authoritative ERP sales orders and accounting ledger services (`CustomerDueController` / `SalesOrderService`). No financial duplicate ledgers or cache tables are permitted.
- **Zero ERP Migration Modifications:** Existing 63 ERP migrations remain frozen. All 18 Gate 1 CRM tables (13 migration units) provide the complete schema foundation. Zero migrations are executed for Gate 2 without a separately authorized change request.
- **Technology Stack Preservation:** Laravel 11.x, PHP 8.2+, Vue 3.5+ (Composition API with `<script setup>`), Inertia.js 3.9+, and Tailwind CSS 3.4+. No external UI libraries or competing SPA routers are permitted.

---

## 2. PRE-FLIGHT REPOSITORY & ENVIRONMENT AUDIT

An exhaustive inspection of the repository verified the baseline state:

| Component | Verified Repository State | Compliance Check |
| :--- | :--- | :--- |
| **Current Branch** | `crm/gate-1-schema` | Verified active branch |
| **Current HEAD** | `a0cd9941ffcfae6e603e0e31c8a5674916442dd4` | Formal Gate 1 acceptance committed |
| **Parent HEAD** | `fb129f7a94b5384a4ef4b208df6f604c1ac6b4b5` | Clarification commit on Gate 1 |
| **Gate 0 Closeout** | `546d423fb94ca59bcdb5d8085c698b5e76754092` | All 16 CRM specifications tracked |
| **Gate 5 Security** | `402b898d68f25c9084cac0b4e7ef612e0402b06c` | Unmodified parent baseline |
| **Working Tree** | Clean prior to planning document additions | Zero uncommitted code artifacts |
| **PHP Runtime** | PHP 8.2.12 (CLI Visual C++ 2019 x64) | Meets `^8.2` requirement |
| **Laravel Framework**| `laravel/framework: ^11.0` | Laravel 11 verified |
| **Inertia Laravel** | `inertiajs/inertia-laravel: ^3.5` | Inertia v3 backend verified |
| **Inertia Vue** | `@inertiajs/vue3: ^3.9.1` | Inertia v3 frontend verified |
| **Vue Runtime** | `vue: ^3.5.43` | Vue 3 Composition API verified |
| **CSS Framework** | `tailwindcss: ^3.4.0` | `@tailwindcss/forms`, `@tailwindcss/typography` |
| **Iconography** | `lucide-vue-next: ^1.0.0` | Lucide icon set standard |
| **Charts** | `apexcharts: ^8.3.1`, `vue3-apexcharts: ^1.11.3` | ApexCharts library verified |
| **Database Engine** | MySQL 8.4.3 LTS (InnoDB, UTF8MB4) | Verified on isolated test DB |

---

## 3. BACKEND ARCHITECTURAL DESIGN

### 3.1 Module Directory Organization
All CRM backend files reside in `app/Modules/Crm/`, adhering to modular domain architecture:

```
app/Modules/Crm/
├── Controllers/
│   ├── CrmActivityController.php
│   ├── CrmContactController.php
│   ├── CrmCustomer360Controller.php
│   ├── CrmCustomerController.php
│   ├── CrmDashboardController.php
│   ├── CrmDealController.php
│   ├── CrmLeadController.php
│   └── CrmPipelineController.php
├── Requests/
│   ├── Activity/
│   │   ├── StoreActivityRequest.php
│   │   └── UpdateActivityRequest.php
│   ├── Contact/
│   │   ├── StoreContactRequest.php
│   │   └── UpdateContactRequest.php
│   ├── Deal/
│   │   ├── StoreDealRequest.php
│   │   ├── UpdateDealRequest.php
│   │   └── TransitionDealStageRequest.php
│   ├── Lead/
│   │   ├── StoreLeadRequest.php
│   │   ├── UpdateLeadRequest.php
│   │   └── ConvertLeadRequest.php
│   └── Pipeline/
│       ├── StorePipelineRequest.php
│       └── UpdatePipelineRequest.php
├── Models/                     (Verified Gate 1 Deliverables)
│   ├── CrmActivity.php
│   ├── CrmChannel.php
│   ├── CrmContact.php
│   ├── CrmDeal.php
│   ├── CrmDealItem.php
│   ├── CrmLead.php
│   ├── CrmPipeline.php
│   └── CrmPipelineStage.php
├── Policies/
│   ├── CrmActivityPolicy.php   (To be created in WP4)
│   ├── CrmContactPolicy.php    (To be created in WP2)
│   ├── CrmDealPolicy.php       (Gate 1 Deliverable)
│   ├── CrmLeadPolicy.php       (Gate 1 Deliverable)
│   └── CrmPipelinePolicy.php   (To be created in WP3)
├── Services/
│   ├── ActivityService.php     (To be created in WP4)
│   ├── ContactService.php      (To be created in WP2)
│   ├── CrmDashboardService.php (To be created in WP6)
│   ├── Customer360Service.php  (To be created in WP5)
│   ├── DealService.php         (To be created in WP3)
│   ├── LeadService.php         (Gate 1 Deliverable — extended in WP1)
│   └── TenantBoundaryValidator.php (Gate 1 Deliverable)
└── CrmServiceProvider.php      (Gate 1 Deliverable — route/policy registration)
```

### 3.2 Authoritative Customer 360 Financial Service Architecture
`Customer360Service` integrates directly with the authoritative ERP sales order and customer due ledger architecture:
- Computes `invoicedDue` via `SalesOrder::where('customer_id', $id)->where('status', '!=', 'cancelled')->sum('due_amount')`.
- Detects formal opening sales orders (`INV-OPEN-xxxx`). If an opening sales order exists, the opening balance is already captured in the `due_amount` sum, preventing double-counting.
- If a customer was registered outside the UI and has an un-invoiced `opening_balance > 0`, it adds the un-invoiced portion as a legacy adjustment.
- Collects order history, payments, refunds, and quotations on a strictly read-only basis.
- Never creates, updates, or deletes journal entries or financial accounts from within the CRM module.

---

## 4. WORK PACKAGE BREAKDOWN & IMPLEMENTATION PHASING

### WP1 — Leads Management
- **Scope:** Complete lead lifecycle: index, search/filter, create, view, edit, assignment, score, activity logging, and conversion into canonical ERP customer.
- **Dependencies:** Gate 1 `CrmLead`, `LeadService`, `CrmLeadPolicy`, `TenantBoundaryValidator`.
- **Backend Deliverables:**
  - `CrmLeadController.php` (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`, `convert`, `bulkAction`)
  - Form Requests: `StoreLeadRequest.php`, `UpdateLeadRequest.php`, `ConvertLeadRequest.php`
  - Extended `LeadService::convertLeadToCustomer` supporting initial deal generation and contact linking.
- **Frontend Deliverables:**
  - `resources/js/Pages/Crm/Leads/Index.vue`
  - `resources/js/Pages/Crm/Leads/Create.vue`
  - `resources/js/Pages/Crm/Leads/Edit.vue`
  - `resources/js/Pages/Crm/Leads/Show.vue`
  - Partials: `LeadActivityTimeline.vue`, `LeadConvertModal.vue`, `LeadForm.vue`
- **Security & Authorization:** `CrmLeadPolicy` enforces tenant boundary, branch restriction (`team_user.warehouse_id`), and view-assigned vs view-all permissions.

### WP2 — B2B Accounts & B2C Customers
- **Scope:** Customer and account management utilizing canonical ERP `customers` master, enriched with `crm_contacts` for B2B stakeholders.
- **Classification Approach:**
  - **Track A (Default Zero-Migration):** Compound heuristic: B2B if `company_name != ''`, or `tax_number != ''`, or `crm_contacts` count > 0; otherwise B2C.
  - **Track B (Separate Proposal):** Optional migration adding `customer_type` enum (`'b2b'`, `'b2c'`) and `assigned_user_id` to `customers`.
- **Backend Deliverables:**
  - `CrmCustomerController.php` (listing with B2B/B2C filter, CRM detail view)
  - `CrmContactController.php` (`store`, `update`, `destroy`)
  - `ContactService.php` (stakeholder management, primary contact enforcement)
  - `CrmContactPolicy.php` (tenant and customer ownership checks)
  - Form Requests: `StoreContactRequest.php`, `UpdateContactRequest.php`
- **Frontend Deliverables:**
  - `resources/js/Pages/Crm/Customers/Index.vue`
  - Partials: `ContactList.vue`, `ContactModal.vue`
- **Duplicate Prevention Strategy:**
  - Phone normalization and duplicate check against active tenant.
  - Tax Number (BIN/TIN) exact match check for businesses.

### WP3 — Sales Pipelines & Deals
- **Scope:** Pipeline setup, stage transitions, Kanban board, deal item line entries, quotation linking, and won/lost auditing.
- **Dependencies:** Gate 1 `CrmPipeline`, `CrmPipelineStage`, `CrmDeal`, `CrmDealItem`, `CrmDealPolicy`.
- **Backend Deliverables:**
  - `CrmPipelineController.php` (pipeline and stage CRUD)
  - `CrmDealController.php` (`index`, `kanban`, `create`, `store`, `show`, `edit`, `update`, `destroy`, `updateStage`)
  - `DealService.php` (deal valuation calculation, stage transition validation, won/lost state transitions)
  - Form Requests: `StoreDealRequest.php`, `UpdateDealRequest.php`, `TransitionDealStageRequest.php`
- **Frontend Deliverables:**
  - `resources/js/Pages/Crm/Deals/Index.vue`
  - `resources/js/Pages/Crm/Deals/Kanban.vue` (interactive drag-and-drop / stage columns)
  - `resources/js/Pages/Crm/Deals/Show.vue`
  - Partials: `DealItemsTable.vue`, `DealStageBar.vue`, `DealLossModal.vue`

### WP4 — Activities & Tasks
- **Scope:** Task scheduling, call logs, meeting notes, follow-up reminders, completion toggles, and unified timelines.
- **Dependencies:** Gate 1 `CrmActivity`, `TenantBoundaryValidator`.
- **Backend Deliverables:**
  - `CrmActivityController.php` (`index`, `store`, `update`, `toggleComplete`, `destroy`)
  - `ActivityService.php` (polymorphic association handling, overdue tracking)
  - `CrmActivityPolicy.php` (tenant verification, own/assigned task edit restrictions)
  - Form Requests: `StoreActivityRequest.php`, `UpdateActivityRequest.php`
- **Frontend Deliverables:**
  - `resources/js/Pages/Crm/Activities/Index.vue`
  - Partials: `ActivityModal.vue`, `ActivityCard.vue`

### WP5 — Customer 360 View
- **Scope:** Comprehensive single-view customer profile consolidating CRM relationships and authoritative ERP operational records.
- **Dependencies:** WP1, WP2, WP3, WP4, ERP `Customer`, `SalesOrder`, `Quotation`, `AccountingService`.
- **Backend Deliverables:**
  - `CrmCustomer360Controller.php` (`show`)
  - `Customer360Service.php` (parallel queries for leads, deals, orders, quotes, activities, and authoritative balance without double-counting)
- **Frontend Deliverables:**
  - `resources/js/Pages/Crm/Customers/Show360.vue`
  - Partials: `CustomerDealsTab.vue`, `CustomerOrdersTab.vue`, `CustomerQuotationsTab.vue`, `CustomerFinancialsCard.vue`

### WP6 — CRM Dashboard & Reports
- **Scope:** Executive and operational CRM metrics, conversion rates, stage-by-stage pipeline values, sales rep leaderboards, and follow-up alerts.
- **Dependencies:** WP1 through WP5.
- **Backend Deliverables:**
  - `CrmDashboardController.php` (`index`)
  - `CrmDashboardService.php` (cached aggregated metrics scoped by tenant and branch)
- **Frontend Deliverables:**
  - `resources/js/Pages/Crm/Dashboard.vue` (ApexCharts pipeline funnel, KPI cards, follow-up list)

---

## 5. OUT-OF-SCOPE SPECIFICATIONS FOR GATE 2

The following subsystems are explicitly deferred to subsequent gates:
1. **Live Communication Integrations:** WhatsApp Cloud API live webhooks, Twilio / Infobip SMS sending, and external email inbound sync (IMAP/POP3) — Deferred to Gate 3.
2. **Marketing Automation & Campaigns:** Outbound marketing email campaigns, broadcast recipient queues, segment auto-responders — Deferred to Gate 3 / 4.
3. **Omnichannel Unified Inbox:** Live multi-agent chat interface with WebSocket streaming — Deferred to Gate 3.
4. **Customer Support Ticketing:** Public support portal and automated SLA routing — Deferred to Gate 4.
5. **Subscription & Billing Plan Alterations:** Modifying SaaS plans, pricing tiers, or billing limits — Strictly frozen.

---

## 6. STOP POINT & AUTHORIZATION GOVERNANCE

This document represents the complete, verified architectural design for Stage 2 CRM Gate 2.

> [!CAUTION]
> **GATE 2 IMPLEMENTATION IS STRICTLY NOT AUTHORIZED.**  
> No application code, controllers, Vue components, routes, or database modifications shall be executed until explicit Project Owner approval is granted.
