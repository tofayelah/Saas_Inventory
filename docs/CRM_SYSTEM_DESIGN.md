# InoManage Cloud Multi-Tenant SaaS ERP
# Stage 2 CRM - System Design Document

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Internal Technical Architecture Specification  
**Status:** Approved for Gate 0 Revision 2.1 Review  
**Target Repository:** `D:\Project\Saas_inventory`

---

## 1. Executive Summary & Vision

InoManage Cloud ERP is an enterprise multi-tenant SaaS application operating on Laravel, MySQL, and Vue 3 / Inertia.js. Following the successful completion and verification of **Stage 1 (Security Hardening, Gates 1 through 5)**, the platform provides robust multi-tenant boundaries (`team_id` tenancy with fail-closed scopes, strict RBAC, authenticated private storage, hardened API token scopes, concurrent transactional integrity, and comprehensive automated test suites).

**Stage 2: Enterprise CRM** extends InoManage Cloud with an integrated, omnichannel Customer Relationship Management suite directly embedded within the existing modular monolith.

### Core Architectural Principles
1. **Zero Customer Master Duplication:** The existing ERP `customers` table remains the single source of financial and counterparty truth. CRM leads convert into ERP customers and deals; B2B and B2C counterparties share the unified ERP ledger. The separate `clients` table used in Project Costing remains completely untouched and outside the scope of CRM Gate 1.
2. **Hybrid B2B & B2C Architecture:** Native support for both complex multi-stakeholder enterprise accounts (B2B) and individual consumers (B2C) within the same tenant workspace.
3. **Omnichannel First:** Native integration with tenant-connected WhatsApp Business Accounts (Meta Cloud API via Platform Embedded Signup), SMS (BulkSMSBD Option A default), and tenant SMTP/Email with a single unified conversation stream.
4. **Strict Multi-Tenant Isolation:** Complete inheritance of Stage 1 security guarantees (`team_id` on all tables, `BelongsToTenant` fail-closed trait, tenant-scoped queues, composite unique constraints, and composite foreign keys wherever technically supported).
5. **Separation of Concerns:** Clear demarcation between CRM Customer Support Cases (inquiries, billing, SLAs) and Workshop Service Tickets (hardware diagnosis, technician bench, spare parts inventory deduction).
6. **Regulatory Compliance & Consent Governance:** Explicit opt-in/opt-out consent tracking with auditable evidence, compliance with Bangladesh Telecommunication Regulatory Commission (BTRC) Do Not Disturb (DND) regulatory requirements, and a mandatory dual-custody campaign approval workflow before marketing dispatches.

---

## 2. Business Model & Functional Scope

```
+-----------------------------------------------------------------------------------+
|                           InoManage Cloud ERP Platform                            |
+-----------------------------------------------------------------------------------+
|                                                                                   |
|   +-----------------------+                         +-------------------------+   |
|   |     Stage 2: CRM      |                         |      Stage 1: ERP       |   |
|   |                       |                         |                         |   |
|   |  - Leads & Scoring    |     Lead Conversion     |  - Customers (Master)   |   |
|   |  - B2B Contacts       +------------------------>|  - Quotations & Orders  |   |
|   |  - Deals & Items      |     Deal Won -> Quote   |  - Invoicing & Dues     |   |
|   |  - Omnichannel Inbox  |                         |  - General Ledger       |   |
|   |  - Campaigns (Authz)  |     Case Escalation     |  - Warehouses / Outlets |   |
|   |  - Support Cases      +------------------------>|  - Service Tickets (Rep)|   |
|   +-----------------------+                         +-------------------------+   |
|                                                                                   |
+-----------------------------------------------------------------------------------+
```

### 2.1 Hybrid B2B and B2C Support
* **B2B Model:**
  * **Accounts:** Represented by `customers` entity with `company_name` populated, along with business tax identifiers.
  * **Contacts:** Multiple individual decision-makers (`crm_contacts`) linked to a single B2B account, storing individual roles (e.g., Procurement Manager, CFO, Site Engineer).
  * **Deals & Pipelines:** Multi-stage sales cycles with milestone probabilities, stakeholder mapping, deal line items (`crm_deal_items`), quotation revisions, and projected close dates.
* **B2C Model:**
  * **Direct Consumers:** Represented by `customers` entity where `company_name` is null, and contact details match the primary individual.
  * **Fast-Track Pipelines:** Streamlined lead-to-order conversions, POS point-of-sale integration, and automated promotional messaging.

### 2.2 Functional Domains
1. **Lead Management:** Multi-source capture (web forms, manual entry, incoming WhatsApp/SMS/Email, API webhooks), automated qualification scoring, duplicate detection, and assignment rules with tenant membership validation.
2. **Account & Contact Management:** Unified 360-degree timeline view of counterparties, cross-referencing past invoices, pending dues, quotations, activity logs, and communication history.
3. **Deal & Pipeline Funnels:** Visual Kanban boards for customizable multi-stage pipelines, line-item pricing (`crm_deal_items`), quotation revision history, win/loss tracking, weighted sales forecasting, and branch-filtered views.
4. **Unified Omnichannel Communications:** Two-way conversational messaging for WhatsApp Business, BulkSMSBD, and tenant-configured SMTP mailboxes.
5. **Marketing Campaigns & Segments:** Dynamic/static cohort segmentation, template management with variable merge tags, regulatory DND suppression, and strict dual-custody approval workflows.
6. **Customer Support Cases:** Omnichannel ticket creation, priority matrix, SLA target tracking, internal collaboration notes, and escalation routing to workshop service tickets when physical hardware repairs are required.
7. **Activities & Productivity:** Tenant-wide and user-specific calendar tasks, call logs, meeting notes, automated reminders, and polymorphic audit-logged interactions.
8. **Automation Engine:** Event-driven rule evaluation restricted to validated allowlisted operations (`assign_user`, `send_template_whatsapp`, `send_template_sms`, `send_template_email`, `change_stage`, `create_activity`, `add_tag`).

---

## 3. System Architecture & Modular Boundaries

The CRM is architected as an internal domain module (`app/Modules/Crm` with PSR-4 namespace `App\Modules\Crm`) adhering to clean layered design principles within the Laravel application:

* **Presentation Layer:** Inertia.js controllers returning Vue 3.5 components using existing application layouts, authenticated via Sanctum and standard session auth.
* **Domain/Service Layer:** Granular business service classes (`LeadService`, `DealService`, `OmnichannelService`, `CampaignService`, `SupportCaseService`, `TenantBoundaryValidator`, `TenantMailService`) encapsulating business rules, tenant context validation, and transaction management.
* **Data Layer:** Eloquent models utilizing the canonical `App\Traits\BelongsToTenant` trait and implementing `\App\Contracts\TenantAware`, ensuring fail-closed `team_id` global scopes on every query.
* **Integration Layer:** Decoupled event listeners and ERP adapters interacting with core ERP services (`CustomerService`, `QuotationService`, `AccountingService`, `ServiceTicketService`) without circular dependencies.
* **Infrastructure Layer:** Dedicated queue channels (`crm-campaigns`, `crm-webhooks`, `crm-messages`), Redis rate limiters, isolated runtime SMTP transports, durable webhook staging (`crm_webhook_events`), and encrypted credential storage (`Crypt::encryptString`).

---

## 4. Hardware & Software Requirements Baseline

| Component | Minimum Specification | Recommended Production |
| :--- | :--- | :--- |
| **PHP Runtime** | PHP 8.2+ with `pcntl`, `bcmath`, `curl`, `mbstring` | PHP 8.3 OPcache enabled, JIT configured |
| **Database** | MySQL 8.0+ or MySQL 8.4 LTS (InnoDB, utf8mb4) | MySQL 8.4 LTS, ACID transactional isolation |
| **Cache & Queues** | Redis 7.0+ (Cluster or Sentinel) | Redis 7.2 with persistent RDB/AOF |
| **Frontend** | Vue 3.5, Inertia.js, Tailwind CSS | Vue 3.5 (Composition API, `<script setup>`) |
| **Third-Party APIs** | Meta WhatsApp Cloud API (v21.0 configured, v19.0+ min), BulkSMSBD REST API v2 | Webhook endpoints secured with TLS 1.3 |

---

## 5. Security & Isolation Invariants

Every CRM component strictly enforces the invariants established in Stage 1:
1. **Tenant Isolation:** Every single CRM database table contains `team_id` with foreign key cascade to `teams(id)`. Every Eloquent model inherits `App\Traits\BelongsToTenant` and implements `\App\Contracts\TenantAware`. No query runs unscoped.
2. **Database Foreign Keys & Composite Relationships:**
   * **Internal CRM-to-CRM (Tier 1):** Enforces composite foreign keys `FOREIGN KEY (team_id, parent_id) REFERENCES crm_table(team_id, id)` wherever parent tables define a composite unique index on `(team_id, id)`.
   * **CRM-to-ERP (Tier 2):** Where existing ERP tables (`customers`, `warehouses`, `quotations`, `service_tickets`, `users`) only expose a single-column primary key `id`, standard foreign keys `FOREIGN KEY (parent_id) REFERENCES erp_table(id)` are supplemented with centralized application-level tenant-matching validation (`TenantBoundaryValidator`) and automated security tests.
   * **Polymorphic Validation:** All polymorphic relations (`crm_conversations`, `crm_activities`, `crm_campaign_recipients`, `crm_automation_logs`) enforce strict `team_id` identity matches before persist.
3. **Branch Access Control & Membership Model:**
   * Reuses the authoritative `Membership` (`team_user.warehouse_id`) model to scope staff.
   * Documented limitation: In the current schema, a user holds one primary branch assignment per tenant membership. Multi-branch visibility is governed by tenant-wide permissions (`crm.leads.view_all`, `crm.deals.view_all`).
   * Every assigned staff member (`assigned_user_id`) is strictly validated for active membership in the active tenant.
4. **Secret Encryption & Webhook Ingress:**
   * Meta WhatsApp webhooks are cryptographically validated against the **Platform Meta App Secret** via `X-Hub-Signature-256`.
   * The destination `waba_id` and `phone_number_id` inside the payload resolve the tenant from `crm_channels`. Unmatched payloads are safely recorded in `crm_webhook_events` with status `'unmatched_channel'` without raising fatal exceptions or re-dispatching.
   * Credentials (tokens, passwords, API keys) are stored encrypted via AES-256-GCM.
5. **Queue & Mailer Isolation:**
   * Queue workers instantiate isolated SMTP transport instances dynamically at runtime without mutating global Laravel mail configuration.
   * Transports are explicitly stopped (`$transport->stop()`), mailer instances purged, and `TenantContext` cleared in `finally` blocks across all success and error paths.
6. **Private Attachments:** All CRM attachments stored on the `private` storage disk and streamed via tenant-authorized controllers with strict MIME and magic-byte checks.

---

## 6. Definition of Ready for Gate 1 (Foundation & Schema)

Stage 2 Gate 0 establishes the complete blueprint. The prerequisites to commence **Gate 1 (Schema & Base Models)** are:
- [x] All Gate 0 design documents reviewed and reconciled to Version 2.1.0.
- [x] 18-table database schema and ERDs specified with composite unique indexes and internal composite FKs.
- [x] Unified customer master strategy approved (`customers` canonical; `clients` left untouched).
- [x] WhatsApp Platform App vs Tenant WABA architecture and signature verification clarified.
- [x] Isolated worker SMTP lifecycle and memory cleanup defined.
- [x] Campaign dual-custody approval lifecycle and DND regulatory consent rules documented.
- [ ] Explicit Git branch creation authorized by the project owner.

---
*Proceed to individual domain specifications in `docs/crm/` for detailed architectural and implementation requirements.*
