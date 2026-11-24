# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
# STAGE 2 CRM — GATE 2 PERMISSION MATRIX & SECURITY ARCHITECTURE
## Role-Based Access Control, Multi-Tenant Boundary Enforcement, and Audit Logging

**Project:** InoManage Cloud Multi-Tenant SaaS ERP  
**Repository:** `D:\Project\Saas_inventory`  
**Current Branch:** `crm/gate-1-schema`  
**Accepted Gate 1 HEAD:** `a0cd9941ffcfae6e603e0e31c8a5674916442dd4`  
**Document Status:** DRAFT FOR PROJECT OWNER APPROVAL (REVISION 2.2 — REFINED)  
**Implementation Authorization:** NOT AUTHORIZED — PLANNING ONLY  

---

## 1. SECURITY FOUNDATION & INHERITED CONTROLS

Stage 2 CRM Gate 2 inherits all multi-tenant security architecture and controls established in **Gate 5 (Security Release Baseline)** and **Gate 1 (Schema & Model Foundation)**.

### 1.1 Inherited Architectural Invariants
1. **TenantScope Fail-Closed:**  
   Every CRM model implements `App\Contracts\TenantAware` and uses `App\Traits\BelongsToTenant`. If executed without an active tenant context (`team_id === null`), the query builder automatically fails closed or throws a `TenantIsolationException`.
2. **Explicit Tenant Context Everywhere:**  
   HTTP requests resolve the tenant strictly from `Auth::user()->current_team_id`. Background jobs and CLI commands require an explicit tenant parameter; no implicit platform-wide or console fallback is permitted.
3. **Branch Truth Source:**  
   Branch membership is resolved strictly from `team_user.warehouse_id` via `App\Models\Membership`. `users.warehouse_id` is **never** used as an authorization fallback.
4. **Mass-Assignment Defense:**  
   `team_id` is protected against HTTP request tampering. All model mutations either sanitize `team_id` from the authenticated session or use `forceFill(['team_id' => $activeTeamId])` inside audited services.
5. **Polymorphic Boundary Defense:**  
   Polymorphic attachments (`crm_activities`) must pass `TenantBoundaryValidator::validatePolymorphicTarget()`, which rejects non-allowlisted models and validates that the referenced entity belongs strictly to the active `team_id`.
6. **Financial Data Immutability within CRM:**  
   CRM policies permit **read-only** presentation of financial balances, invoices, and payment histories. CRM controllers possess no authority to create, update, or delete accounting ledgers or journal entries.

---

## 2. FOUR-TIER AUTHORIZATION HIERARCHY

The platform enforces a 4-tier access scope hierarchy across all CRM operations:

```
[Tier 1: Platform Super Admin]
   └── Bypasses tenant policies via isSuperAdmin() for platform administration;
       isolated from tenant data unless explicitly impersonating.

[Tier 2: Tenant Owner / Tenant Admin]
   └── Has full administrative visibility and management rights across the entire active tenant.
       Owner is identified via ownsTeam() or 'owner' role. Admin holds 'admin' role.

[Tier 3: Branch / Warehouse Manager]
   └── Constrained by Membership warehouse_id (team_user.warehouse_id).
       Can view and manage leads and deals belonging to their assigned physical branch.

[Tier 4: Assigned Sales Representative]
   └── Constrained to own records (assigned_user_id === user.id).
       Holds 'crm.leads.view_assigned' or 'crm.deals.view_assigned'.
```

---

## 3. EXPLICIT ROLE & PERMISSION MATRIX

The following table defines the granular permissions required for all Core CRM actions across standard tenant roles (`owner`, `admin`, `branch_manager`, `staff` / `sales_rep`):

| Module / Operation | Permission String | Owner | Admin | Branch Manager | Sales Rep (Staff) | Policy Enforcement Rule |
| :--- | :--- | :---: | :---: | :---: | :---: | :--- |
| **Leads: View All** | `crm.leads.view_all` | ✅ | ✅ | ❌ | ❌ | Tenant-wide lead visibility |
| **Leads: View Branch**| `crm.leads.view_branch` | ✅ | ✅ | ✅ | ❌ | Matches `team_user.warehouse_id === crm_leads.warehouse_id` |
| **Leads: View Assigned**| `crm.leads.view_assigned` | ✅ | ✅ | ✅ | ✅ | Matches `crm_leads.assigned_user_id === user.id` |
| **Leads: Create** | `crm.leads.create` | ✅ | ✅ | ✅ | ✅ | Tenant boundary verified |
| **Leads: Edit** | `crm.leads.edit` | ✅ | ✅ | ✅ (Branch) | ✅ (Assigned) | Policy verifies branch / assignment match |
| **Leads: Convert** | `crm.leads.convert` | ✅ | ✅ | ✅ | ❌ | Atomic customer conversion with row lock |
| **Leads: Delete** | `crm.leads.delete` | ✅ | ✅ | ❌ | ❌ | Destructive action; owners/admins only |
| **Leads: Bulk Action** | `crm.leads.bulk_action` | ✅ | ✅ | ❌ | ❌ | Scoped bulk reassignment |
| **Customers: View** | `crm.customers.view` | ✅ | ✅ | ✅ | ✅ | Read canonical customer master |
| **Customers: Edit** | `crm.customers.edit` | ✅ | ✅ | ❌ | ❌ | Updates canonical customer attributes |
| **Customer 360: View**| `crm.customers.360` | ✅ | ✅ | ✅ | ✅ | Access consolidated 360 profile |
| **Contacts: Manage** | `crm.contacts.manage` | ✅ | ✅ | ✅ | ✅ | CRUD on `crm_contacts` for a customer |
| **Deals: View All** | `crm.deals.view_all` | ✅ | ✅ | ❌ | ❌ | Tenant-wide deal visibility |
| **Deals: View Branch**| `crm.deals.view_branch` | ✅ | ✅ | ✅ | ❌ | Matches `team_user.warehouse_id === crm_deals.warehouse_id` |
| **Deals: View Assigned**| `crm.deals.view_assigned` | ✅ | ✅ | ✅ | ✅ | Matches `crm_deals.assigned_user_id === user.id` |
| **Deals: Create** | `crm.deals.create` | ✅ | ✅ | ✅ | ✅ | Creates deal in active pipeline |
| **Deals: Edit** | `crm.deals.edit` | ✅ | ✅ | ✅ (Branch) | ✅ (Assigned) | Updates deal details |
| **Deals: Stage Move** | `crm.deals.stage_move` | ✅ | ✅ | ✅ (Branch) | ✅ (Assigned) | Transitions Kanban stage |
| **Deals: Close Won/Lost**| `crm.deals.close` | ✅ | ✅ | ✅ | ❌ | Finalizes commercial deal outcome |
| **Deals: Delete** | `crm.deals.delete` | ✅ | ✅ | ❌ | ❌ | Destructive action; owners/admins only |
| **Pipelines: Configure**| `crm.pipelines.manage` | ✅ | ✅ | ❌ | ❌ | Manages pipelines and stage funnels |
| **Activities: View** | `crm.activities.view` | ✅ | ✅ | ✅ | ✅ | Views activity timeline on accessible records |
| **Activities: Create**| `crm.activities.create` | ✅ | ✅ | ✅ | ✅ | Logs call, meeting, task, note |
| **Activities: Edit/Done**| `crm.activities.manage` | ✅ | ✅ | ✅ | ✅ (Own) | Completes or updates activity |
| **Dashboard: View** | `crm.dashboard.view` | ✅ | ✅ | ✅ | ✅ | Metrics scoped to role access tier |
| **Export Data** | `crm.export.data` | ✅ | ❌ | ❌ | ❌ | Owner-only high-risk data export |

---

## 4. AUDIT LOGGING & SECURITY EVENTS

To meet SaaS enterprise compliance, all high-impact and sensitive operations generate persistent security audit logs.

### 4.1 Security Audit Events (`SecurityAuditLog`)
The following actions trigger a mandatory record in the `security_audit_logs` table:
1. **Lead Conversion (`crm.lead_converted`):** Logs `lead_id`, `customer_id`, created `deal_id`, and converting `user_id`.
2. **Deal Closed Lost (`crm.deal_lost`):** Logs `deal_id`, `monetary_value`, `loss_reason`, and updating `user_id`.
3. **Lead Bulk Reassignment (`crm.leads_bulk_reassigned`):** Logs array of `lead_ids`, previous `assigned_user_id`, new `assigned_user_id`.
4. **Customer Data Export (`crm.customer_export`):** Logs timestamp, record count, filter parameters, and exporting `user_id`.

### 4.2 Activity Logging (`spatie/laravel-activitylog`)
Standard CRUD changes on leads, deals, contacts, and pipelines are tracked automatically via Spatie Activitylog with tenant scoping (`team_id` recorded on every activity entry).
