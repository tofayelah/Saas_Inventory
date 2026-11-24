# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
# STAGE 2 CRM — GATE 2 ANTIGRAVITY EXECUTION PLAN
## Step-by-Step Phased Engineering Roadmap, Checkpoints, and Release Governance

**Project:** InoManage Cloud Multi-Tenant SaaS ERP  
**Repository:** `D:\Project\Saas_inventory`  
**Current Branch:** `crm/gate-1-schema`  
**Accepted Gate 1 HEAD:** `a0cd9941ffcfae6e603e0e31c8a5674916442dd4`  
**Document Status:** DRAFT FOR PROJECT OWNER APPROVAL (REVISION 2.2 — REFINED)  
**Implementation Authorization:** NOT AUTHORIZED — PLANNING ONLY  

---

## 1. ENGINEERING EXECUTION FRAMEWORK

This document defines the strict, phased execution plan for the development team upon receiving explicit Project Owner authorization to implement **Stage 2 CRM Gate 2 (Core CRM First)**.

### 1.1 Strict Governance Invariants
1. **No Out-of-Sequence Implementation:** Work Packages must be executed in exact sequential order (WP0 -> WP1 -> WP2 -> WP3 -> WP4 -> WP5 -> WP6 -> Final Audit).
2. **Explicit Verification Checkpoint at Every Stage:** Each Work Package requires a dedicated test run and git verification checkpoint before the next package may begin.
3. **No Migration Creation without Explicit Authorization:** Core CRM relies on the 18 Gate 1 CRM tables. Zero migrations shall be executed unless the Project Owner specifically authorizes the separate schema change proposal for `customer_type` and `assigned_user_id`.
4. **Canonical Master Preservation:** `customers` remains canonical; `clients` remains untouched; accounting double-entry ledgers remain non-mutated.

---

## 2. PHASE-BY-PHASE IMPLEMENTATION ROADMAP

```
+-----------------------------------------------------------------------------------+
| PHASE 0: Pre-Flight Verification & Git Governance Checkpoint                      |
|          - Verify branch, clean working tree, dependency lockfiles                |
+-----------------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------------+
| PHASE 1: WP1 Leads Management                                                     |
|          - CrmLeadController, Form Requests, Extended LeadService                 |
|          - Pages/Crm/Leads/* (Index, Show, Create, Edit, LeadConvertModal)        |
|          - Feature & MySQL Concurrency Tests                                      |
+-----------------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------------+
| PHASE 2: WP2 B2B Accounts & B2C Customers                                         |
|          - CrmCustomerController, CrmContactController, ContactService            |
|          - Compound B2B/B2C Heuristic Classification & Duplicate Protection       |
|          - Pages/Crm/Customers/*, ContactList.vue, ContactModal.vue               |
|          - Stakeholder & Primary Contact Tests                                    |
+-----------------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------------+
| PHASE 3: WP3 Sales Pipelines & Deals                                              |
|          - CrmPipelineController, CrmDealController, DealService                  |
|          - Pages/Crm/Deals/* (Index, Kanban, Show, Create, DealLossModal)         |
|          - Stage Transition, Product Line Items & Kanban Drag-and-Drop Tests      |
+-----------------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------------+
| PHASE 4: WP4 Activities & Tasks                                                   |
|          - CrmActivityController, ActivityService, CrmActivityPolicy              |
|          - Pages/Crm/Activities/* (Index, ActivityModal, Agenda View)             |
|          - Polymorphic Association & Task Completion Tests                        |
+-----------------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------------+
| PHASE 5: WP5 Customer 360 Consolidated Profile                                    |
|          - CrmCustomer360Controller, Customer360Service                           |
|          - Authoritative ERP Ledger Integration (Anti-Double-Counting Formula)     |
|          - Pages/Crm/Customers/Show360.vue (Tabbed Profile & Financials Card)     |
|          - Data Integrity & Read-Only Financial Ledger Verification Tests         |
+-----------------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------------+
| PHASE 6: WP6 CRM Dashboard & Executive Reporting                                  |
|          - CrmDashboardController, CrmDashboardService                            |
|          - Pages/Crm/Dashboard.vue (ApexCharts Funnel, KPI Cards, Agenda)         |
|          - Scoped Metrics, Cache Isolation & Branch Restriction Tests             |
+-----------------------------------------------------------------------------------+
                                      |
                                      v
+-----------------------------------------------------------------------------------+
| PHASE 7: Final Security Audit, Quality Assurance, and Gate 2 Acceptance           |
|          - Full 16-Suite QA Execution, Gate 5 & Gate 1 Regression Check           |
|          - Release Documentation, Git Commit, and Project Owner Sign-off          |
+-----------------------------------------------------------------------------------+
```

---

## 3. WORK PACKAGE EXECUTION PROTOCOLS

### WP0: Pre-Flight Verification & Git Checkpoint
- **Objective:** Establish the clean baseline before writing any implementation code.
- **Actions:**
  1. Confirm Git branch is `crm/gate-2-core` (branched from accepted Gate 1 commit `a0cd994`).
  2. Verify working tree is clean.
  3. Run baseline test suite (`php artisan test --filter="Crm|Security"`) and record 100% pass baseline.

### WP1: Leads Management
- **Dependencies:** WP0, Gate 1 `CrmLead`, `LeadService`, `CrmLeadPolicy`.
- **Backend Files to Implement:**
  - `app/Modules/Crm/Controllers/CrmLeadController.php`
  - `app/Modules/Crm/Requests/Lead/StoreLeadRequest.php`
  - `app/Modules/Crm/Requests/Lead/UpdateLeadRequest.php`
  - `app/Modules/Crm/Requests/Lead/ConvertLeadRequest.php`
  - Extend `app/Modules/Crm/Services/LeadService.php` (support initial deal creation & contact linking)
- **Frontend Files to Implement:**
  - `resources/js/Pages/Crm/Leads/Index.vue`
  - `resources/js/Pages/Crm/Leads/Create.vue`
  - `resources/js/Pages/Crm/Leads/Edit.vue`
  - `resources/js/Pages/Crm/Leads/Show.vue`
  - `resources/js/Pages/Crm/Leads/Partials/LeadActivityTimeline.vue`
  - `resources/js/Pages/Crm/Leads/Partials/LeadConvertModal.vue`
  - `resources/js/Pages/Crm/Leads/Partials/LeadForm.vue`
- **Security Controls:** `CrmLeadPolicy` enforces tenant isolation, `team_user.warehouse_id` branch scoping, and assigned-user authorization.
- **Verification Tests:** Run Suite 4 (Lead CRUD), Suite 5 (Lead Conversion), Suite 6 (Concurrent Conversion).

### WP2: B2B Accounts & B2C Customer Integration
- **Dependencies:** WP1, ERP `Customer`, Gate 1 `CrmContact`.
- **Classification Handling:** Implements Track A compound heuristic (`company_name`, `tax_number`, `crm_contacts`) unless Project Owner authorizes Track B migration.
- **Backend Files to Implement:**
  - `app/Modules/Crm/Controllers/CrmCustomerController.php`
  - `app/Modules/Crm/Controllers/CrmContactController.php`
  - `app/Modules/Crm/Services/ContactService.php`
  - `app/Modules/Crm/Policies/CrmContactPolicy.php`
  - `app/Modules/Crm/Requests/Contact/StoreContactRequest.php`
  - `app/Modules/Crm/Requests/Contact/UpdateContactRequest.php`
- **Frontend Files to Implement:**
  - `resources/js/Pages/Crm/Customers/Index.vue`
  - `resources/js/Pages/Crm/Customers/Partials/ContactList.vue`
  - `resources/js/Pages/Crm/Customers/Partials/ContactModal.vue`
- **Verification Tests:** Run Suite 7 (Duplicate Customer & TIN Check), Suite 8 (B2B Accounts & Contacts).

### WP3: Sales Pipelines & Deals
- **Dependencies:** WP1, WP2, Gate 1 `CrmPipeline`, `CrmPipelineStage`, `CrmDeal`, `CrmDealItem`.
- **Backend Files to Implement:**
  - `app/Modules/Crm/Controllers/CrmPipelineController.php`
  - `app/Modules/Crm/Controllers/CrmDealController.php`
  - `app/Modules/Crm/Services/DealService.php`
  - `app/Modules/Crm/Policies/CrmPipelinePolicy.php`
  - `app/Modules/Crm/Requests/Deal/StoreDealRequest.php`
  - `app/Modules/Crm/Requests/Deal/UpdateDealRequest.php`
  - `app/Modules/Crm/Requests/Deal/TransitionDealStageRequest.php`
- **Frontend Files to Implement:**
  - `resources/js/Pages/Crm/Deals/Index.vue`
  - `resources/js/Pages/Crm/Deals/Kanban.vue`
  - `resources/js/Pages/Crm/Deals/Create.vue`
  - `resources/js/Pages/Crm/Deals/Edit.vue`
  - `resources/js/Pages/Crm/Deals/Show.vue`
  - `resources/js/Pages/Crm/Deals/Partials/DealItemsTable.vue`
  - `resources/js/Pages/Crm/Deals/Partials/DealStageBar.vue`
  - `resources/js/Pages/Crm/Deals/Partials/DealLossModal.vue`
  - `resources/js/Pages/Crm/Pipelines/Index.vue`
- **Verification Tests:** Run Suite 9 (Deal CRUD & Stages), Suite 10 (Deal Ownership).

### WP4: Activities & Tasks
- **Dependencies:** WP1, WP2, WP3, Gate 1 `CrmActivity`, `TenantBoundaryValidator`.
- **Backend Files to Implement:**
  - `app/Modules/Crm/Controllers/CrmActivityController.php`
  - `app/Modules/Crm/Services/ActivityService.php`
  - `app/Modules/Crm/Policies/CrmActivityPolicy.php`
  - `app/Modules/Crm/Requests/Activity/StoreActivityRequest.php`
  - `app/Modules/Crm/Requests/Activity/UpdateActivityRequest.php`
- **Frontend Files to Implement:**
  - `resources/js/Pages/Crm/Activities/Index.vue`
  - `resources/js/Pages/Crm/Activities/Partials/ActivityModal.vue`
  - `resources/js/Pages/Crm/Activities/Partials/ActivityCard.vue`
- **Verification Tests:** Run Suite 11 (Activity Polymorphic Authorization).

### WP5: Customer 360 View
- **Dependencies:** WP1, WP2, WP3, WP4, ERP `Customer`, `SalesOrder`, `Quotation`.
- **Backend Files to Implement:**
  - `app/Modules/Crm/Controllers/CrmCustomer360Controller.php`
  - `app/Modules/Crm/Services/Customer360Service.php` (executes anti-double-counting opening invoice formula)
- **Frontend Files to Implement:**
  - `resources/js/Pages/Crm/Customers/Show360.vue`
  - `resources/js/Pages/Crm/Customers/Partials/CustomerDealsTab.vue`
  - `resources/js/Pages/Crm/Customers/Partials/CustomerOrdersTab.vue`
  - `resources/js/Pages/Crm/Customers/Partials/CustomerQuotationsTab.vue`
  - `resources/js/Pages/Crm/Customers/Partials/CustomerFinancialsCard.vue`
- **Verification Tests:** Run Suite 12 (Customer 360 Integrity), Suite 13 (Read-Only Ledger & Opening Balance Precision).

### WP6: CRM Dashboard & Executive Reporting
- **Dependencies:** WP1 through WP5.
- **Backend Files to Implement:**
  - `app/Modules/Crm/Controllers/CrmDashboardController.php`
  - `app/Modules/Crm/Services/CrmDashboardService.php`
- **Frontend Files to Implement:**
  - `resources/js/Pages/Crm/Dashboard.vue`
- **Verification Tests:** Run Suite 14 (Search & Pagination), Suite 15 (Inertia Contracts).

### Phase 7: Release Audit & Gate 2 Acceptance
- **Actions:**
  1. Execute all 16 Gate 2 test suites.
  2. Execute complete Gate 1 and Gate 5 regression suites (`tests/Feature/Security/*`, `tests/Feature/Crm/*`).
  3. Verify 0 test failures, 0 warnings.
  4. Perform static analysis and audit git diff.
  5. Author `docs/CRM_GATE_2_ACCEPTANCE.md`.
  6. Present acceptance report to Project Owner.

---

## 4. MANDATORY STOP POINT

> [!IMPORTANT]
> **GATE 2 EXECUTION PLAN REFINED — IMPLEMENTATION STOP POINT ACTIVE**  
> All planning documents have been refined and aligned with the existing codebase and ERP financial architecture.  
> **Under NO circumstances shall WP0, WP1, or any application coding commence without explicit, written Project Owner authorization.**
