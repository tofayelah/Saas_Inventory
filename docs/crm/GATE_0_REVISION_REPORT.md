# InoManage Cloud Multi-Tenant SaaS ERP
# Stage 2 CRM - Gate 0 Architecture Revision & Security Reconciliation Report

**Document Version:** 1.0.0  
**Date:** November 23, 2026  
**Auditor:** Lead SaaS & Security Architect  
**Target Repository:** `D:\Project\Saas_inventory`  
**Current Baseline Branch:** `security/gate-5-security-release-audit`  
**Baseline Commit:** `402b898d68f25c9084cac0b4e7ef612e0402b06c`  
**Execution Type:** Strictly DOCUMENTATION ONLY (Zero Code / Schema Changes)  
**Status:** COMPLETE — ALL REVISIONS VERIFIED

---

## 1. Exact Git Evidence & Baseline Lineage

| Verification Metric | Value | Verification Command | Status |
| :--- | :--- | :--- | :---: |
| **Current Branch** | `security/gate-5-security-release-audit` | `git branch --show-current` | **PASS** |
| **Full Commit SHA** | `402b898d68f25c9084cac0b4e7ef612e0402b06c` | `git rev-parse HEAD` | **PASS** |
| **Commit Lineage** | Gate 5 (`402b898`) <- Gate 4.1 (`b17570b`) <- Gate 4 (`6836384`) <- Gate 3.1 (`35b5dbb`) <- Gate 3 (`e18f322`) | `git log -n 5 --oneline --graph` | **PASS** |
| **Working Tree Status** | Clean (0 tracked files modified) | `git status --short` | **PASS** |
| **Unstaged Changes** | Empty (0 insertions, 0 deletions) | `git diff --stat` | **PASS** |
| **Staged Changes** | Empty (0 staged changes) | `git diff --cached --stat` | **PASS** |
| **Tracked ERP Baseline** | `docs/ERP_SYSTEM_DESIGN.md` untouched | `git diff HEAD docs/ERP_SYSTEM_DESIGN.md` | **PASS** |

---

## 2. Mandatory Findings & Architectural Resolutions

### Finding 1: Tenant-Safe Database Relationships
* **Problem:** Standard single-column foreign keys `FOREIGN KEY (parent_id) REFERENCES parent_table(id)` do not prevent cross-tenant referencing at the database engine level.
* **Resolution:** Implemented a **Two-Tiered Foreign Key Protocol**:
  * **Tier 1 (Internal CRM):** Parent CRM tables define `UNIQUE(team_id, id)`. Child CRM tables enforce composite foreign keys:
    - `crm_pipeline_stages (team_id, pipeline_id) -> crm_pipelines (team_id, id)`
    - `crm_deal_items (team_id, deal_id) -> crm_deals (team_id, id)`
    - `crm_campaign_recipients (team_id, campaign_id) -> crm_campaigns (team_id, id)`
    - `crm_messages (team_id, conversation_id) -> crm_conversations (team_id, id)`
    - `crm_automation_logs (team_id, rule_id) -> crm_automation_rules (team_id, id)`
  * **Tier 2 (CRM-to-ERP):** Existing core tables (`customers`, `warehouses`, `quotations`, `service_tickets`, `users`) lack `(team_id, id)` composite unique indexes. They enforce single-column database FKs supplemented by **mandatory application-level validation hooks** in Eloquent `saving` events and automated security tests in `TenantIsolationTest`.

### Finding 2: Branch Authorization via Authoritative Membership Model
* **Problem:** Assuming a single global `$user->warehouse_id` represents all user permissions across all tenants violates multi-tenant Jetstream membership design.
* **Resolution:** 
  * Reconciled branch access using the authoritative `Membership` (`team_user.warehouse_id`) pivot model. A user can belong to multiple teams with distinct branch assignments in each.
  * Defined granular access separately:
    - **Owner & Admin:** Full cross-branch access across all warehouses.
    - **Sales Manager:** Full pipeline visibility with multi-branch filtering and transfer authority.
    - **Sales Representative:** Restricted to assigned records (`assigned_user_id == user.id`) OR records belonging to their active `membership.warehouse_id`.
    - **Support Agent:** Restricted to assigned cases OR cases belonging to their active `membership.warehouse_id`.
    - **Viewer:** Read-only access across authorized branches.
  * Reassignment & Transfer Auditing: Changing `warehouse_id` on a lead or deal requires `crm.leads.transfer` or `crm.deals.transfer`, verifies the target warehouse is active in the same tenant, and logs an immutable entry to `activity_log`.

### Finding 3: WhatsApp Integration Architecture & Signature Verification
* **Problem:** Ambiguity regarding Meta App ownership vs WABA ownership and incorrect assumption that tenant app secrets verify webhooks.
* **Resolution:**
  * **Ownership Model:** The InoManage SaaS platform owns a single verified **Meta Developer App**. Each tenant owns their own **WhatsApp Business Account (WABA)** connected via **Meta Embedded Signup**.
  * **Cryptographic Verification:** Meta signs all webhook events using the **Platform Meta App Secret**! Inbound webhooks at `POST /api/v1/crm/webhooks/whatsapp` verify `X-Hub-Signature-256` using constant-time hash comparison against `config('services.meta.app_secret')`.
  * **Tenant Resolution:** The controller parses `entry[].id` (WABA ID) and `entry[].changes[].value.metadata.phone_number_id` from the payload and looks up `team_id` in `crm_channels`.
  * **Durable Event Storage & Replay Defense:** Raw payloads and SHA-256 hashes are recorded in `crm_webhook_events`. Duplicates within 24 hours are acknowledged and bypassed without re-dispatching queue jobs.
  * **Configurable Graph Version:** Defaulting to `v21.0` (configurable via `config('services.meta.graph_version')`, minimum supported `v19.0`).

### Finding 4: Queue & SMTP Isolation
* **Problem:** Mutating global Laravel mail configuration (`config(['mail.mailers.smtp => ...'])`) in persistent queue workers causes severe cross-tenant credential leakage.
* **Resolution:**
  * **Zero Global Config Mutation:** `TenantMailService` instantiates a dedicated `Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport` instance dynamically for each tenant job.
  * **Worker Memory Cleanup:** In the worker job's `finally` block, mailer transport instances are purged and `$tenantContext->clear()` is invoked.
  * **Zero Secret Serialization:** Plaintext credentials are never passed in job constructors or serialized in Redis.
  * **Test Strategy:** Added sequential multi-tenant worker tests (`TenantIsolationTest`) executing Tenant 1 and Tenant 2 jobs on the same worker process to prove zero state leakage.

### Finding 5: Consent, Suppression & Campaign Governance
* **Problem:** Vague opt-in tracking and lack of clarity on regulatory obligations vs tenant opt-outs.
* **Resolution:**
  * **Opt-In Evidence:** `crm_consents` stores `opt_in_evidence`, `source` (`web_form`, `pos_receipt`, `keyword_stop`, `btrc_dnd`), `timestamp`, `purpose` (`marketing`, `transactional`, `service_alert`), and `status IN ('opted_in', 'opted_out', 'dnd_national')`.
  * **BTRC National DND Compliance:** Full support for importing/syncing Bangladesh Telecommunication Regulatory Commission national Do Not Disturb lists (`dnd_national`).
  * **Automated Keyword Trapping:** Inbound SMS/WhatsApp keywords (`STOP`, `UNSUBSCRIBE`, `CANCEL`, `বন্ধ`, `বাতিল`) automatically update `crm_consents` to `opted_out`.
  * **Dual-Custody Campaign Approval:** Campaign author (`created_by`) cannot approve the campaign. Dual custody applies strictly to all users including Tenant Owners (`created_by !== approver_id`). No single-custody exception is permitted.
  * **Pre-Dispatch Server-Side Validation:** Inside `ProcessCampaignBatchJob`, an atomic database transaction with `lockForUpdate()` validates approval status, approver ID, template compliance, and recipient DND/opt-out suppression immediately before external API transmission. Material edits to approved campaigns immediately reset status to `draft`.

### Finding 6: Database Completeness & Consistency
* **Problem:** Missing deal line items, missing durable webhook event storage, and undefined automation actions.
* **Resolution:**
  * Added `crm_deal_items` table: Opportunities track individual proposed products, quantities, unit prices, and subtotals, mapping directly to `quotation_items`.
  * Added `crm_webhook_events` table: Durable staging table for incoming webhooks, replay protection, and audit logs.
  * Restricted Automation Actions: Restricted to an explicit allowlist:
    `['assign_user', 'send_template_whatsapp', 'send_template_sms', 'send_template_email', 'change_stage', 'create_activity', 'add_tag']`.
  * Boundary Invariant: The `clients` table created in Project Costing remains completely untouched and outside CRM Gate 1. CRM uses `customers` as canonical master.

---

## 3. Revised Database Table Inventory (18 Tables)

| # | Table Name | Purpose | Primary / Composite Unique Keys |
| :---: | :--- | :--- | :--- |
| **1** | `crm_channels` | Tenant integration credentials (WA, SMS, Mail) | `UNIQUE (team_id, channel_type, identifier)`, `UNIQUE (team_id, id)` |
| **2** | `crm_webhook_events` | Durable raw webhook staging & replay protection | `INDEX (team_id, external_event_id)`, `INDEX (provider, payload_hash)` |
| **3** | `crm_leads` | Prospective customer leads & qualification | `UNIQUE (team_id, lead_number)` |
| **4** | `crm_contacts` | Multi-stakeholder contacts under B2B `customers` | `INDEX (team_id, customer_id)`, `INDEX (team_id, phone)` |
| **5** | `crm_pipelines` | Sales funnel pipelines | `UNIQUE (team_id, slug)`, `UNIQUE (team_id, id)` |
| **6** | `crm_pipeline_stages`| Pipeline stages & probability percentages | `FOREIGN KEY (team_id, pipeline_id) REFERENCES crm_pipelines(team_id, id)` |
| **7** | `crm_deals` | Commercial sales opportunities | `UNIQUE (team_id, deal_number)`, `UNIQUE (team_id, id)` |
| **8** | `crm_deal_items` | Opportunity line items & product pricing | `FOREIGN KEY (team_id, deal_id) REFERENCES crm_deals(team_id, id)` |
| **9** | `crm_conversations` | Omnichannel chat threads | `UNIQUE (team_id, id)`, `INDEX (team_id, remote_identifier)` |
| **10**| `crm_messages` | Inbound & outbound chat messages | `FOREIGN KEY (team_id, conversation_id) REFERENCES crm_conversations(team_id, id)` |
| **11**| `crm_campaigns` | Marketing broadcasts & dual approval | `UNIQUE (team_id, campaign_code)`, `UNIQUE (team_id, id)` |
| **12**| `crm_campaign_recipients`| Individual campaign recipients & status | `FOREIGN KEY (team_id, campaign_id) REFERENCES crm_campaigns(team_id, id)` |
| **13**| `crm_segments` | Static & dynamic audience cohorts | `INDEX (team_id, name)` |
| **14**| `crm_consents` | Opt-in evidence & regulatory DND registry | `UNIQUE (team_id, channel_type, destination, purpose)` |
| **15**| `crm_support_cases` | Post-sale customer tickets & SLAs | `UNIQUE (team_id, case_number)` |
| **16**| `crm_activities` | Tasks, calls, meetings, and notes | `INDEX (team_id, related_type, related_id)`, `INDEX (team_id, assigned_user_id)` |
| **17**| `crm_automation_rules`| Event-driven automation rules | `UNIQUE (team_id, id)` |
| **18**| `crm_automation_logs` | Automation execution audit trails | `FOREIGN KEY (team_id, rule_id) REFERENCES crm_automation_rules(team_id, id)` |

---

## 4. Documentation Revision Inventory

All 11 existing CRM documentation files have been updated to **Version 2.0.0**, and the revision report has been created:

1. [docs/CRM_SYSTEM_DESIGN.md](file:///d:/Project/Saas_inventory/docs/CRM_SYSTEM_DESIGN.md) (v2.0.0)
2. [docs/crm/CRM_ARCHITECTURE.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_ARCHITECTURE.md) (v2.0.0)
3. [docs/crm/CRM_DATABASE_DESIGN.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_DATABASE_DESIGN.md) (v2.0.0)
4. [docs/crm/CRM_SECURITY_DESIGN.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_SECURITY_DESIGN.md) (v2.0.0)
5. [docs/crm/CRM_PERMISSION_MATRIX.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_PERMISSION_MATRIX.md) (v2.0.0)
6. [docs/crm/CRM_API_SPECIFICATION.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_API_SPECIFICATION.md) (v2.0.0)
7. [docs/crm/CRM_COMMUNICATION_ARCHITECTURE.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_COMMUNICATION_ARCHITECTURE.md) (v2.0.0)
8. [docs/crm/CRM_ERP_INTEGRATION.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_ERP_INTEGRATION.md) (v2.0.0)
9. [docs/crm/CRM_INFRASTRUCTURE_DESIGN.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_INFRASTRUCTURE_DESIGN.md) (v2.0.0)
10. [docs/crm/CRM_TEST_STRATEGY.md](file:///d:/Project/Saas_inventory/docs/crm/CRM_TEST_STRATEGY.md) (v2.0.0)
11. [docs/crm/GATE_0_RECONCILIATION_REPORT.md](file:///d:/Project/Saas_inventory/docs/crm/GATE_0_RECONCILIATION_REPORT.md) (v2.0.0)
12. [docs/crm/GATE_0_REVISION_REPORT.md](file:///d:/Project/Saas_inventory/docs/crm/GATE_0_REVISION_REPORT.md) (v1.0.0 - Current Document)

---

## 5. Confirmed Strategic Decisions for Project Owner

| Strategic Area | Adopted Decision | Security & Compliance Rationale |
| :--- | :--- | :--- |
| **BulkSMSBD Gateway Model** | **Option A: Tenant-Specific Credentials** | Tenant-owned BulkSMSBD credentials establish separate provider account ownership and billing responsibility. The SaaS platform remains responsible for its own applicable security, privacy, contractual and regulatory obligations according to its role and applicable law. |
| **Dual-Custody Campaign Approval** | **Strict Dual-Custody Without Exception** | Universal rule: `created_by !== approver_id` applies to all users including Tenant Owners. No single-custody exception exists, guaranteeing tamper-proof broadcast compliance. |

---

## 6. Confirmation of Restrictions Compliance

* **Application Code Changes:** 0 lines of PHP, JS, Vue, or SQL written.
* **Migrations / Database Operations:** 0 migrations created or executed.
* **Configuration / Packages:** 0 changes to `.env`, `composer.json`, or `package.json`.
* **Branch / Lineage:** Branch remains `security/gate-5-security-release-audit` at commit `402b898d68f25c9084cac0b4e7ef612e0402b06c`.
* **Tracked Docs:** [docs/ERP_SYSTEM_DESIGN.md](file:///d:/Project/Saas_inventory/docs/ERP_SYSTEM_DESIGN.md) remains strictly untouched.

---
*End of Gate 0 Revision Report. Execution is stopped. Awaiting explicit project owner authorization to proceed to Stage 2 Gate 1.*
