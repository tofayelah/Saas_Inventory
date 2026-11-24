# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
# STAGE 2 CRM — GATE 2 TEST STRATEGY & VERIFICATION MATRIX
## Comprehensive Quality Assurance, MySQL Integration, Concurrency, and Security Regression Framework

**Project:** InoManage Cloud Multi-Tenant SaaS ERP  
**Repository:** `D:\Project\Saas_inventory`  
**Current Branch:** `crm/gate-1-schema`  
**Accepted Gate 1 HEAD:** `a0cd9941ffcfae6e603e0e31c8a5674916442dd4`  
**Document Status:** DRAFT FOR PROJECT OWNER APPROVAL (REVISION 2.2 — REFINED)  
**Implementation Authorization:** NOT AUTHORIZED — PLANNING ONLY  

---

## 1. TESTING ARCHITECTURE & ENVIRONMENT MATRIX

To guarantee enterprise multi-tenant reliability and database engine fidelity, Gate 2 quality assurance employs a dual-runner testing architecture:

```
+-----------------------------------------------------------------------------------+
|                            GATE 2 TEST SUITE PYRAMID                              |
+-----------------------------------------------------------------------------------+
| Tier 3: MySQL 8.4.3 LTS Isolated Integration Tests (inomanage_crm_test_isolated)   |
|         - InnoDB composite foreign key rejection                                  |
|         - Pessimistic locking (lockForUpdate) and concurrent race conditions      |
|         - Transactional rollback integrity                                        |
+-----------------------------------------------------------------------------------+
| Tier 2: Feature & Inertia Controller Integration Tests                            |
|         - Route authorization, middleware, Form Requests                          |
|         - Policy enforcement (tenant, branch, assignment)                        |
|         - Inertia props contracts and view rendering                              |
+-----------------------------------------------------------------------------------+
| Tier 1: Unit & Domain Service Tests (SQLite in-memory)                            |
|         - Boundary validation, scoring logic, model attribute casting             |
+-----------------------------------------------------------------------------------+
```

### 1.1 Mandatory Engine Invariant
> [!IMPORTANT]
> **MYSQL 8.4 INTEGRATION REQUIREMENT:**  
> SQLite in-memory test suites do not enforce MySQL InnoDB locking mechanics, transaction isolation levels, or foreign key constraints under concurrent execution. SQLite unit tests must never be substituted for MySQL integration verification. All concurrency, composite foreign key, and locking tests must run against the verified isolated MySQL database instance (`inomanage_crm_test_isolated`).

---

## 2. THE 16 MANDATORY GATE 2 TEST SUITES

### Suite 1: Tenant Isolation & Fail-Closed Scoping
- **Test File:** `tests/Feature/Crm/Gate2/TenantIsolationFailClosedTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Verify that every CRM controller, query, and service strictly fails closed when tenant context is absent or manipulated.
- **Key Test Cases:**
  1. `test_unauthenticated_request_is_redirected_to_login`: Accessing `/crm/*` without session redirects to login.
  2. `test_user_without_current_team_id_is_blocked`: Authenticated user with `current_team_id === null` receives HTTP 403 or redirect to team onboarding.
  3. `test_query_without_tenant_scope_fails_closed`: Direct model queries without tenant scope return 0 records across all CRM tables.
  4. `test_tampered_team_id_in_payload_is_discarded`: Submitting a forged `team_id` in POST/PUT payload is sanitized, preserving session tenant.

### Suite 2: Cross-Tenant Record Access Denial
- **Test File:** `tests/Feature/Crm/Gate2/CrossTenantAccessDenialTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Ensure Tenant B can never view, update, convert, or delete Tenant A's leads, deals, contacts, or activities.
- **Key Test Cases:**
  1. `test_tenant_b_cannot_view_tenant_a_lead`: GET `/crm/leads/{tenantA_lead}` by Tenant B returns HTTP 404 (not found in tenant scope).
  2. `test_tenant_b_cannot_update_tenant_a_deal`: PUT `/crm/deals/{tenantA_deal}` by Tenant B returns HTTP 404 / 403.
  3. `test_tenant_b_cannot_convert_tenant_a_lead`: POST `/crm/leads/{tenantA_lead}/convert` by Tenant B is aborted.
  4. `test_cross_tenant_composite_fk_rejected_by_mysql`: Direct database insertion linking Tenant A lead to Tenant B customer is rejected by InnoDB foreign key constraint (Error 1452).

### Suite 3: Branch / Warehouse Scoping (`team_user.warehouse_id`)
- **Test File:** `tests/Feature/Crm/Gate2/BranchWarehouseAuthorizationTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Enforce physical branch boundaries using `team_user.warehouse_id` as the sole truth source.
- **Key Test Cases:**
  1. `test_branch_sales_rep_sees_only_assigned_branch_leads`: Staff member assigned to Branch 1 cannot see leads tagged to Branch 2 in list query.
  2. `test_branch_manager_sees_all_leads_within_assigned_branch`: Manager at Branch 1 sees all leads in Branch 1, but zero leads in Branch 2.
  3. `test_tenant_owner_sees_all_branches`: Owner without warehouse constraint sees all tenant leads across all branches.
  4. `test_fallback_to_users_warehouse_id_is_strictly_prohibited`: Verifies authorization fails if user has `users.warehouse_id` but missing `team_user.warehouse_id`.

### Suite 4: Lead CRUD & Form Validation
- **Test File:** `tests/Feature/Crm/Gate2/LeadCrudAndValidationTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Validate complete lead lifecycle operations and request validation rules.
- **Key Test Cases:**
  1. `test_lead_creation_with_valid_data`: Creates lead, verifies database record, and verifies generated `lead_number`.
  2. `test_lead_creation_fails_when_required_fields_missing`: Submitting empty title or invalid source enum triggers validation errors.
  3. `test_lead_update_persists_changes_and_logs_activity`: Updating status logs an activity event on the lead.
  4. `test_lead_soft_deletion`: Deleting lead sets `deleted_at` (if soft deletes enabled) and removes from active index.

### Suite 5: Lead Conversion into Canonical Customer
- **Test File:** `tests/Feature/Crm/Gate2/LeadConversionTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Verify accurate conversion of prospective lead into an authoritative ERP customer.
- **Key Test Cases:**
  1. `test_lead_converts_to_new_customer_when_not_previously_existing`: Lead details map accurately into `customers` table with `is_active = true`.
  2. `test_lead_conversion_links_existing_customer_by_phone_or_email`: Re-uses existing customer if phone/email matches.
  3. `test_lead_status_updated_to_converted_with_timestamp`: Lead status becomes `'converted'`, `converted_customer_id` is populated, `converted_at` is set.
  4. `test_lead_conversion_creates_initial_deal_if_requested`: Generates `crm_deals` record linked to customer and lead.

### Suite 6: Concurrent Lead Conversion Race Condition Safety
- **Test File:** `tests/Feature/Crm/Gate2/ConcurrentLeadConversionTest.php`
- **Runner:** MySQL 8.4 (Isolated DB)
- **Objective:** Guarantee pessimistic row locking (`lockForUpdate`) prevents double customer creation under simultaneous HTTP requests.
- **Key Test Cases:**
  1. `test_simultaneous_conversion_requests_produce_single_customer`: Two concurrent processes attempting to convert Lead #1 result in exactly 1 `customers` record created and both processes receiving the same customer ID.
  2. `test_lock_for_update_prevents_dirty_reads_during_conversion`: Validates transaction isolation and rollback behavior on failure.

### Suite 7: Duplicate Customer Prevention
- **Test File:** `tests/Feature/Crm/Gate2/DuplicateCustomerPreventionTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Prevent redundant customer records within the same tenant while allowing identical phone/email across different tenants.
- **Key Test Cases:**
  1. `test_duplicate_phone_in_same_tenant_is_detected`: Warns or merges lead into existing customer.
  2. `test_duplicate_tax_number_in_same_tenant_is_blocked`: Detects corporate duplicate matching BIN/TIN.
  3. `test_identical_phone_in_different_tenants_is_permitted`: Tenant A and Tenant B can each independently have a customer with `phone = '01700000000'`.

### Suite 8: B2B Account and Contact Stakeholder Relationships
- **Test File:** `tests/Feature/Crm/Gate2/B2bAccountAndContactsTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Verify multi-stakeholder management for corporate accounts via `crm_contacts` and compound heuristic classification.
- **Key Test Cases:**
  1. `test_b2b_compound_heuristic_identifies_company`: Customer with `company_name`, `tax_number`, or contacts is classified as B2B Account.
  2. `test_b2c_individual_classified_accurately`: Customer with empty `company_name`, null `tax_number`, and 0 contacts is classified as B2C Customer.
  3. `test_multiple_contacts_attached_to_single_customer`: Can attach multiple stakeholders (e.g., Procurement, Accounts).
  4. `test_primary_contact_flag_enforcement`: Only one contact per customer can be marked `is_primary = true`.
  5. `test_deleting_customer_cascades_to_contacts`: Deleting customer removes associated `crm_contacts`.

### Suite 9: Deal CRUD & Pipeline Stage Transitions
- **Test File:** `tests/Feature/Crm/Gate2/DealCrudAndStageTransitionsTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Test deal lifecycle, product line items, stage progressions, and won/lost state transitions.
- **Key Test Cases:**
  1. `test_deal_created_with_product_line_items`: Creates deal, adds `crm_deal_items`, and calculates `monetary_value`.
  2. `test_stage_transition_updates_probability`: Moving deal between stages updates expected close valuation.
  3. `test_closing_deal_as_lost_requires_loss_reason`: Attempting to transition to lost stage without reason triggers validation error.
  4. `test_closing_deal_as_won_sets_actual_close_date`: Sets `status = 'won'` and records current date.

### Suite 10: Deal Ownership & Representative Restrictions
- **Test File:** `tests/Feature/Crm/Gate2/DealOwnershipRestrictionsTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Validate that sales representatives can only view or move deals assigned to them or their branch.
- **Key Test Cases:**
  1. `test_sales_rep_cannot_move_unassigned_deal_on_kanban`: Attempting drag-and-drop on colleague's deal returns HTTP 403.
  2. `test_manager_can_reassign_deal_to_different_rep`: Manager can update `assigned_user_id`.

### Suite 11: Activity & Task Polymorphic Authorization
- **Test File:** `tests/Feature/Crm/Gate2/ActivityPolymorphicAuthorizationTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Enforce boundary checks on polymorphic activity attachments.
- **Key Test Cases:**
  1. `test_activity_attached_to_lead`: Logs call on lead; activity appears in lead timeline.
  2. `test_activity_attached_to_deal`: Logs meeting on deal; activity appears in deal timeline.
  3. `test_activity_cross_tenant_target_rejected`: Attempting to attach an activity to Tenant B's customer throws `TenantIsolationException`.
  4. `test_invalid_polymorphic_class_rejected`: Attempting to attach to non-allowlisted model is blocked.

### Suite 12: Customer 360 Consolidated Data Integrity
- **Test File:** `tests/Feature/Crm/Gate2/Customer360DataIntegrityTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Verify that Customer 360 aggregates all related entities accurately without missing or orphan records.
- **Key Test Cases:**
  1. `test_360_aggregates_all_customer_deals`: Returns accurate count and list of deals for target customer.
  2. `test_360_aggregates_sales_orders_and_quotes`: Verifies historical ERP orders and quotations are listed.
  3. `test_360_unified_timeline_merges_deal_and_customer_activities`: Combines activities across all child records into single chronological stream.

### Suite 13: Read-Only Financial Ledger Presentation & Anti-Double-Counting
- **Test File:** `tests/Feature/Crm/Gate2/ReadOnlyFinancialLedgerTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Ensure financial figures displayed in CRM 360 match ERP double-entry ledgers, accurately reflect `INV-OPEN-xxxx` orders, and prevent double counting of opening balances.
- **Key Test Cases:**
  1. `test_customer_due_balance_does_not_double_count_opening_invoice`: Customer with opening balance 5000 and `INV-OPEN-xxxx` order with `due_amount = 5000` reports exactly 5000 due, not 10000.
  2. `test_customer_due_balance_reflects_partial_payments`: Recording a payment of 2000 via `CustomerDueController::pay()` reduces reported due to 3000.
  3. `test_customer_due_balance_reflects_returns`: Processing a product return via `ReturnService::processReturn()` reduces reported due accordingly.
  4. `test_crm_cannot_modify_financial_accounts_or_ledgers`: Confirms no CRM controller or service invokes accounting mutation methods.

### Suite 14: Search, Filtering, and Pagination
- **Test File:** `tests/Feature/Crm/Gate2/SearchFilterPaginationTest.php`
- **Runner:** SQLite & MySQL
- **Objective:** Validate search accuracy across phone, name, email, and company, and verify pagination metadata.
- **Key Test Cases:**
  1. `test_lead_search_by_partial_phone`: Submitting `017` matches `01712345678`.
  2. `test_pagination_preserves_query_string`: Page 2 link retains `search` and `status` query parameters.

### Suite 15: Vue/Inertia Page Authorization & Props Contracts
- **Test File:** `tests/Feature/Crm/Gate2/InertiaPageContractsTest.php`
- **Runner:** SQLite
- **Objective:** Verify Inertia responses return exact expected component paths and data prop schemas.
- **Key Test Cases:**
  1. `test_lead_index_renders_correct_component_and_props`: Returns `Pages/Crm/Leads/Index` with `leads`, `filters`, `statuses`.
  2. `test_kanban_renders_correct_component_and_pipeline_stages`: Returns `Pages/Crm/Deals/Kanban` with `pipelines` and `stages`.

### Suite 16: Gate 1 & Gate 5 Regression Suite Preservation
- **Test File:** `tests/Feature/Crm/CrmMandatorySecurityContractsTest.php`, `tests/Feature/Crm/CrmTenantIsolationTest.php`, `tests/Feature/Security/*`
- **Runner:** SQLite & MySQL
- **Objective:** Continuous verification that all 107 existing security, isolation, and regression tests remain 100% PASS (0 failures, 0 errors).

---

## 3. TEST EXECUTION SCRIPTS & PASS/FAIL CRITERIA

### 3.1 Execution Commands
```bash
# 1. Run Gate 2 Feature & Controller Test Suite (Fast CI Runner)
php artisan test --filter="Gate2"

# 2. Run MySQL 8.4 Integration Suite (Isolated Database)
php artisan test --filter="CrmMySqlIntegrationTest"

# 3. Full Security & Isolation Regression Suite (Zero Regressions)
php artisan test --testsuite=Feature --filter="Crm|Security"
```

### 3.2 Gate 2 Acceptance Quality Gates
- **Code Coverage:** 100% of CRM controller routes and service methods covered.
- **Zero Failures:** 0 failed tests, 0 warnings, 0 skipped tests.
- **MySQL Execution:** 100% pass on MySQL 8.4 LTS instance.
