# InoManage Cloud Multi-Tenant SaaS ERP
# Stage 2 CRM Gate 0 - Historical Reconciliation & Decision Report (Revised)

**Document Version:** 2.1.0  
**Date:** November 24, 2026  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Executive & Technical Milestone Sign-off  
**Path:** `docs/crm/GATE_0_RECONCILIATION_REPORT.md`

---

## 1. Executive Summary

Stage 2 CRM Gate 0 represents the **Architecture and Design Phase** for introducing an enterprise-grade Customer Relationship Management suite into InoManage Cloud Multi-Tenant SaaS ERP.

Following strict design-only authorization, zero application code, database migrations, or configuration files were modified. The architecture, database ERDs, security specifications, permission matrices, API contracts, UI/UX designs, telecommunications protocols, ERP integration touchpoints, and test strategies have been revised and reconciled across 12 comprehensive documents.

---

## 2. Historical Codebase Reconciliation & Revision Audit

### 2.1 Reconciled Architectural Findings (Revision 2.0.0)

| Architectural Finding | Initial Gate 0 Baseline | Revision 2.0.0 Resolution |
| :--- | :--- | :--- |
| **Foreign Key Safety** | Proposed single-column foreign keys across all tables. | **Two-Tiered FK Strategy:** Internal CRM tables enforce composite foreign keys `FOREIGN KEY (team_id, parent_id) REFERENCES crm_parent(team_id, id)`. Legacy ERP tables (`customers`, `warehouses`, `quotations`, `service_tickets`, `users`) enforce single-column database FKs supplemented by application-level validation hooks and automated security tests. |
| **Branch Authorization** | Assumed single `$user->warehouse_id`. | **Authoritative Membership Model:** Uses `team_user.warehouse_id` (`Membership` pivot) to resolve branch assignments per tenant. Distinct access rules for Owner, Admin, Sales Manager, Sales Rep, Support Agent, and Viewer. Branch transfers require `crm.deals.transfer` and generate audit logs. |
| **WhatsApp Integration** | Assumed tenant app secret verified webhooks. | **Platform App vs Tenant WABA:** Clarified that the InoManage platform owns the registered Meta App; tenants connect their WABA via Meta Embedded Signup. Meta signs inbound webhooks using the **Platform Meta App Secret**. Ingress validates the platform signature, records raw payload in `crm_webhook_events` for replay defense, and resolves `team_id` via `crm_channels`. Graph API version is configurable (`config('services.meta.graph_version', 'v21.0')`). |
| **Queue & Mailer Isolation** | Mutated global mailer config in workers. | **Zero Global Config Mutation:** Queue workers build isolated `EsmtpTransport` instances dynamically per tenant, purging mailer state and clearing `TenantContext` in `finally` blocks. |
| **Consent & Governance** | Basic opt-in/opt-out flag. | **Opt-In Evidence & BTRC DND Compliance:** `crm_consents` tracks `opt_in_evidence`, `source`, `purpose`, `timestamp`, and `status IN ('opted_in', 'opted_out', 'dnd_national')`. Inbound keyword traps (`STOP`, `বন্ধ`) auto-opt-out. Pre-dispatch atomic `lockForUpdate` validates approval, consent, suppression, and quotas. |
| **Deal Line Items** | Deal value stored as flat numeric field. | **`crm_deal_items` Added:** Deals track individual proposed products, quantities, and unit prices, bridging seamlessly to `quotation_items`. |
| **Webhook Event Storage** | Processed webhooks directly in memory. | **`crm_webhook_events` Added:** Stores raw payloads, hashes, and status for replay defense, audit logging, and durable processing. |
| **Project Costing Boundary** | Proposed future migration of `clients`. | **Strict Boundary Invariant:** `clients` table remains completely untouched and outside CRM Gate 1. Any future migration must be a separately approved ERP project. |

---

## 3. Approved Business Decisions Log

1. **Business Model:** Hybrid B2B and B2C architecture natively supported in the same tenant workspace.
2. **CRM Scope:** Complete Enterprise CRM across 18 database entities.
3. **WhatsApp Provider:** Tenant-owned WhatsApp Business Accounts connected via official Meta Cloud API v21.0 (Embedded Signup).
4. **SMS Gateway:** BulkSMSBD selected as initial SMS aggregator for Bangladesh (Option A default).
5. **Email Provider:** Tenant-owned SMTP / Email relay (isolated runtime mailer instantiation).
6. **Campaign Approval:** Mandatory dual-custody approval lifecycle before any promotional broadcast is dispatched.
7. **Social Media Channels:** Facebook Lead Ads and Messenger integration deferred to a subsequent phase.
8. **Financial Truth:** ERP double-entry accounting remains the sole financial authority; CRM queries accounting via service contracts.

---

## 4. Definition of Ready for Gate 1 (Foundation & Schema)

The prerequisites to proceed to **Stage 2, Gate 1 (Foundation & Schema)** are:
- [x] All 12 Gate 0 architectural documents revised and aligned with repository realities.
- [x] 18-table database schema and ERDs specified with composite unique indexes and internal composite FKs.
- [x] Zero changes to application code, migrations, or database during Gate 0.
- [x] Git working tree verified clean.
- [ ] Explicit project owner authorization to create the Gate 1 branch (`crm/gate-1-schema`).

---
*End of Gate 0 Reconciliation Report (Revised).*
