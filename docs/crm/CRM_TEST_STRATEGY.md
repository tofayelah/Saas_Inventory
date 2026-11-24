# InoManage Cloud Multi-Tenant SaaS ERP
# CRM Quality Assurance & Test Strategy Specification

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Quality Assurance & Testing Architecture  
**Path:** `docs/crm/CRM_TEST_STRATEGY.md`

---

## 1. Quality Assurance Philosophy & Testing Pyramid

In accordance with the standards verified during **Stage 1 (Gate 5)**, where 76 dedicated multi-tenant security tests and 439 application tests achieved a 100% pass baseline, the CRM module must adhere to strict, automated, regression-free test automation.

```
+-----------------------------------------------------------------------------------------+
|                                    Testing Pyramid                                      |
+-----------------------------------------------------------------------------------------+
|                                                                                         |
|                                         / \                                             |
|                                        /   \                                            |
|                                       / E2E \       End-to-End Workflow & UI Tests      |
|                                      / Tests \      (Inertia Page Props & State)        |
|                                     /---------\                                         |
|                                    / Integration\   Dual-Tenant Isolation Tests,        |
|                                   /  & Security  \  Sequential Worker Isolation Tests   |
|                                  /----------------\                                     |
|                                 /    Unit Tests    \ Service Logic, DTOs, Policies,     |
|                                /                    \ Formatters, Regex & Calculators   |
|                               +----------------------+                                  |
|                                                                                         |
+-----------------------------------------------------------------------------------------+
```

---

## 2. Core Automated Test Suites

### 2.1 Multi-Tenant Isolation & Penetration Suite (`tests/Feature/Crm/TenantIsolationTest.php`)
1. **Tier 1 Composite Foreign Key Enforcement:**
   * Tenant 1 creates a `crm_pipelines` record.
   * Tenant 2 attempts to create a `crm_pipeline_stages` record referencing Tenant 1's `pipeline_id` with `team_id = 2`.
   * **Assertion:** MySQL InnoDB must reject the insert with a foreign key constraint violation.
2. **Tier 2 Application-Level ERP Foreign Key Enforcement:**
   * Tenant 1 creates a Customer.
   * Tenant 2 attempts to create a `crm_deals` record referencing Tenant 1's `customer_id`.
   * **Assertion:** `TenantBoundaryValidator` must throw `TenantIsolationException` before SQL execution.
3. **Polymorphic Cross-Tenant Protection:**
   * Tenant 1 creates a Deal.
   * Tenant 2 attempts to create a `crm_activities` record linked to Tenant 1's deal ID.
   * **Assertion:** `TenantBoundaryValidator::validatePolymorphicTarget` must reject the operation.
4. **Assigned User Membership Enforcement:**
   * Tenant 1 attempts to assign a Lead to a User who is only a member of Tenant 2.
   * **Assertion:** `TenantBoundaryValidator::validateAssignedUser` must throw `TenantIsolationException`.
5. **Sequential Multi-Tenant Worker Security Test:**
   * Dispatch `SendTenantMailJob` for Tenant 1 with Tenant 1 SMTP credentials.
   * Run worker; immediately dispatch `SendTenantMailJob` for Tenant 2 on the same worker process.
   * **Assertion:** Verify Tenant 2 mailer strictly uses Tenant 2 credentials; verify Tenant 1 mailer transport was stopped and purged; assert `TenantContext` was cleared between runs.

### 2.2 Webhook Signature & Replay Protection Suite (`tests/Feature/Crm/WebhookSecurityTest.php`)
1. **Platform Signature Verification:**
   * Post mock payload to `POST /api/v1/crm/webhooks/whatsapp` signed with invalid secret.
   * **Assertion:** Must return HTTP 403 Forbidden.
   * Post payload signed with **Platform Meta App Secret**.
   * **Assertion:** Must return HTTP 200 within 500ms; verify payload is stored in `crm_webhook_events`.
2. **Unknown Channel Graceful Handling:**
   * Post validly signed payload referencing an unregistered `phone_number_id`.
   * **Assertion:** Returns HTTP 200 with `status = 'unmatched_channel'`; logs warning; dispatches zero worker jobs.
3. **Webhook Replay Protection:**
   * Post the exact same payload twice within 24 hours.
   * **Assertion:** Second request returns HTTP 200 with status `'duplicate'` and dispatches zero duplicate background jobs.

### 2.3 Dual-Custody Campaign Approval & Pre-Dispatch Suite (`tests/Feature/Crm/CampaignSecurityTest.php`)
1. **Self-Approval Bypass Prevention:**
   * User creates Campaign. User attempts `POST /crm/campaigns/{id}/approve`.
   * **Assertion:** Must fail with HTTP 403 Forbidden (*"Creators cannot approve their own campaigns"*).
2. **Strict Dual Custody Blocks Owner Self-Approval (`test_strict_dual_custody_blocks_owner_self_approval`):**
   * Tenant Owner creates a Campaign and attempts to approve their own campaign.
   * **Assertion:** Must fail unconditionally with HTTP 403 Forbidden. Dual custody applies to all users including Tenant Owners; no single-custody exception or override flag is permitted.
3. **Approved Campaign Edit Invalidation (`test_editing_approved_campaign_resets_status_to_draft`):**
   * Tenant creates, submits, and successfully approves a campaign via a second authorized user.
   * A user performs a material edit (e.g., changes message body, template, audience segment, or scheduled time).
   * **Assertion:** Approval is immediately invalidated, `approved_by` and `approved_at` are cleared, and campaign status resets to `'draft'`, requiring re-submission and dual approval.
4. **Pre-Dispatch Regulatory DND & Suppression:**
   * Recipient marked `'dnd_national'` or `'opted_out'` in `crm_consents`.
   * Run campaign dispatch worker.
   * **Assertion:** Worker skips recipient; logs status `'suppressed'`; asserts zero mock telecommunications API calls.

### 2.4 ERP Core Integration & Deal Quotation Suite (`tests/Feature/Crm/ErpIntegrationTest.php`)
1. **Deal Items to Quotation Generation & Revisions:**
   * Create Deal with 3 `crm_deal_items`.
   * Call `generateQuotation()`.
   * **Assertion:** Verifies core ERP `quotations` record is generated with 3 `quotation_items`; verifies `quotations.deal_id` links to deal; verifies deal's `quotation_id` links to new quote.
2. **Support Case Escalation to Workshop:**
   * Create Customer Support Case. Escalate to workshop.
   * **Assertion:** Verifies core ERP `service_tickets` record created; verifies `escalated_service_ticket_id` linked on case.

---

### 2.5 Mandatory Security & Concurrency Test Contracts (Gate 0 Condition Register)

The following eight automated test scenarios constitute mandatory preconditions for Gate 1 sign-off. Each contract must be fully implemented and verified in the test suite:

1. **`test_query_builder_update_without_tenant_scope_is_prevented()`**
   * *Architecture Boundary:* Clarifies that raw Laravel Query Builder operations do not automatically apply Eloquent global scopes. All supported CRM bulk update/delete operations must strictly pass through designated Action or Repository boundaries.
   * *Contract:* The test invokes the CRM Bulk Action / Repository layer with an un-scoped or cross-tenant query criteria and verifies that the operation throws a `TenantBoundaryException` and refuses execution before hitting the database.

2. **`test_sales_rep_cannot_read_deals_outside_assigned_branch()`**
   * *Branch Authorization:* Validates multi-branch vs single-branch scoping using `team_user.warehouse_id`.
   * *Contract:* A sales representative assigned to Branch A attempts to read, list, or export deals belonging to Branch B under the same tenant. The policy must reject the request with HTTP 403 Forbidden or filter the query so that Branch B deals are completely invisible.

3. **`test_concurrent_webhook_deliveries_handled_safely_via_unique_index()`**
   * *Inbound Webhook Concurrency:* Tests race conditions when webhooks are redelivered simultaneously.
   * *Contract:* Two concurrent simulated HTTP POST requests deliver the identical provider event identifier. The database unique index (`provider, external_event_id` or `provider, provider_account_id, external_event_id`) guarantees that exactly one record is inserted; the competing thread catches the duplicate key exception and returns HTTP 200 without creating duplicate asynchronous jobs or corrupting state.

4. **`test_duplicate_message_status_callback_is_idempotent()`**
   * *Callback State Idempotency:* Validates repeated delivery/read receipt callbacks from SMS or WhatsApp gateways.
   * *Contract:* Sending duplicate status callbacks (e.g. `'delivered'` received twice) updates timestamps and status idempotently without side-effects, duplicate activity logs, or invalid state regressions.

5. **`test_editing_approved_campaign_resets_status_to_draft()`**
   * *Dual-Custody Integrity:* Validates that approved campaign contents cannot be altered without re-approval.
   * *Contract:* Once a campaign has been approved by an authorized second party (`status = 'approved'`), any material mutation to its template, body, audience segment, or schedule immediately resets `status = 'draft'`, sets `approved_by = null`, `approved_at = null`, and logs the invalidation.

6. **`test_strict_dual_custody_blocks_owner_self_approval()`**
   * *Unconditional Dual Custody:* Validates that the Creator !== Approver constraint applies universally.
   * *Contract:* A user with the `Owner` role creates a campaign and calls `POST /crm/campaigns/{id}/approve`. The request fails unconditionally with HTTP 403 Forbidden. No single-custody exception flag or bypass logic is allowed in the application.

7. **`test_smtp_transport_socket_closed_and_purged_on_both_success_and_failure()`**
   * *Worker Memory & Socket Teardown:* Validates that dedicated per-tenant Symfony Mailer transport with explicit shutdown and cleanup does not leak sockets or state across queued jobs.
   * *Contract:* Dispatches sequential tenant mail jobs on the same worker process. The test asserts that upon both successful send and uncaught transport exceptions (e.g., connection timeout or authentication failure), the dedicated per-tenant Symfony Mailer transport socket is explicitly disconnected, purged from memory, and tenant context is flushed.

8. **`test_concurrent_lead_conversion_creates_single_customer_cleanly()`**
   * *Lead Conversion Atomicity:* Validates that parallel conversion requests for the same lead do not create duplicate ERP customers.
   * *Contract:* Two simultaneous requests attempt to convert Lead X. The conversion service executes within a DB transaction with a pessimistic row lock (`lockForUpdate`). Exactly one `customers` record is created; the second request detects that `converted_customer_id` is already populated and returns the existing customer instance cleanly.

---

## 3. Test Execution & CI/CD Pipeline

All CRM test suites will run under PHPUnit / Pest:
```bash
php artisan test --filter=Crm
```
* **Performance Gate:** The complete CRM test suite must execute in under 30 seconds against the local MySQL test database.
* **Code Coverage Baseline:** Minimum 90% line coverage required across all CRM Service classes and Policies before Gate sign-off.

---
*Next: Comprehensive historical reconciliation and decision log are presented in `docs/crm/GATE_0_RECONCILIATION_REPORT.md`.*
