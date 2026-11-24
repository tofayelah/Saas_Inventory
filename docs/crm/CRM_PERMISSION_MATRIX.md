# InoManage Cloud Multi-Tenant SaaS ERP
# CRM Granular Permission & Role-Based Access Control (RBAC) Matrix

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Security & Access Control Specification  
**Path:** `docs/crm/CRM_PERMISSION_MATRIX.md`

---

## 1. Authoritative Membership & Branch Access Architecture

In InoManage Cloud ERP, a user's organizational and branch affiliation is determined by the **team membership pivot model** (`team_user` / `App\Models\Membership`), which records the user's role and designated physical location (`warehouse_id`) for that specific tenant.

```
+-----------------------------------------------------------------------------------------+
|                                  Tenant Context (Team)                                  |
+-----------------------------------------------------------------------------------------+
                                             |
                                             v
               +-----------------------------------------------------------+
               |              Membership (team_user pivot)                 |
               |  - user_id                                                |
               |  - team_id                                                |
               |  - role: owner | admin | sales_mgr | sales_rep | agent    |
               |  - warehouse_id: Single primary branch per tenant         |
               +-----------------------------------------------------------+
                                             |
                     +-----------------------+-----------------------+
                     |                                               |
                     v                                               v
+------------------------------------------+       +------------------------------------+
|          Tenant-Wide Access              |       |      Branch / Assigned Scoped      |
|  (Owner, Admin, Sales Manager)           |       |     (Sales Rep, Support Agent)     |
|  - Full cross-branch visibility          |       |  - Own/Assigned: assigned_user = ? |
|  - Multi-warehouse pipeline filtering    |       |  - Branch: warehouse_id = member's |
|  - Branch transfer authorization         |       |  - Denied outside assigned branch  |
+------------------------------------------+       +------------------------------------+
```

### 1.1 Resolution Order & Single-Warehouse Limitation per Tenant
1. **Current Schema Architecture:** In the existing database schema, `team_user` contains a single nullable `warehouse_id` foreign key. A user may belong to multiple tenants with a different branch assignment in each. However, **within any single tenant workspace, standard staff have a single designated primary branch assignment**.
2. **Multi-Branch Visibility via Permissions:** Multi-branch access within a single tenant is not governed by multiple database pivot rows, but by granting tenant-wide view permissions (`crm.leads.view_all`, `crm.deals.view_all`, `crm.cases.view_all`). Users with these permissions (Managers, Admins, Owners) can view and filter across all branches.
3. **Assigned User Membership Validation:** Whenever a record is assigned to a user (`assigned_user_id`), the system strictly verifies:
   ```php
   if (!Membership::where('team_id', $teamId)->where('user_id', $assignedUserId)->exists()) {
       throw new TenantIsolationException("Assigned user is not an active member of the active tenant.");
   }
   ```

---

## 2. Access Scope Definitions

Every CRM domain record is categorized into four distinct access levels:
* **Own-Record Access:** The record was authored by the authenticated user (`created_by === auth()->id()`).
* **Assigned-Record Access:** The record is assigned to the authenticated user (`assigned_user_id === auth()->id()`).
* **Branch-Record Access:** The record is assigned to the user's active membership branch (`warehouse_id === membership->warehouse_id`).
* **Tenant-Wide Access:** The record belongs to the active tenant workspace, regardless of branch or assigned user (`team_id === activeTenantId`).

---

## 3. Explicit Role-Permission Matrix

Every permission cell in the matrix below is explicitly defined as **ALLOW** or **DENY**. No cell is left blank or ambiguous.

| Permission String | Owner | Admin | Sales Manager | Sales Rep | Support Agent | Marketing Mgr | Viewer |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `crm.leads.view_all` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **ALLOW** | **ALLOW** |
| `crm.leads.view_assigned` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.leads.create` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **ALLOW** | **DENY** |
| `crm.leads.edit` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.leads.convert` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.leads.transfer` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **DENY** |
| `crm.leads.delete` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **DENY** |
| `crm.deals.view_all` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **ALLOW** |
| `crm.deals.view_assigned` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.deals.create` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.deals.edit` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.deals.change_stage` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.deals.generate_quote`| **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** |
| `crm.deals.transfer` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **DENY** |
| `crm.deals.delete` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **DENY** |
| `crm.pipelines.manage` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **DENY** |
| `crm.conversations.view_all`| **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **ALLOW** | **DENY** | **ALLOW** |
| `crm.conversations.reply` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** |
| `crm.conversations.assign`| **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **ALLOW** | **DENY** | **DENY** |
| `crm.campaigns.create` | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **ALLOW** | **DENY** |
| `crm.campaigns.edit` | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **ALLOW** | **DENY** |
| `crm.campaigns.approve` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY**\* | **DENY** |
| `crm.campaigns.dispatch`| **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **DENY** |
| `crm.campaigns.delete` | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **ALLOW** | **DENY** |
| `crm.cases.view_all` | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **ALLOW** | **DENY** | **ALLOW** |
| `crm.cases.create` | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** |
| `crm.cases.edit` | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **ALLOW** | **DENY** | **DENY** |
| `crm.cases.escalate_repair`| **ALLOW** | **ALLOW** | **DENY** | **DENY** | **ALLOW** | **DENY** | **DENY** |
| `crm.cases.delete` | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **ALLOW** | **DENY** | **DENY** |
| `crm.channels.configure`| **ALLOW** | **ALLOW** | **DENY** | **DENY** | **DENY** | **DENY** | **DENY** |
| `crm.reports.view` | **ALLOW** | **ALLOW** | **ALLOW** | **DENY** | **DENY** | **ALLOW** | **ALLOW** |

*\*Note: Marketing Managers cannot approve their own campaigns. Campaign approval requires independent dual-control authorization from an Admin, Owner, or Sales Manager.*

---

## 4. Branch Transfer & Manual Reassignment Audit Requirements

When a record's branch (`warehouse_id`) or assigned staff (`assigned_user_id`) is changed:
1. **Authorization Check:** The user initiating the change must hold `crm.leads.transfer` or `crm.deals.transfer`. Sales Reps cannot reassign records outside their branch.
2. **Target Branch Verification:** The target `warehouse_id` must belong to the active `team_id` and have `is_active = 1`.
3. **Target User Membership Verification:** The target `assigned_user_id` must be an active member of `team_id` in `team_user`.
4. **Structured Audit Trail:** An automated activity log entry is written to `crm_activities` and dispatched to `activity_log`:
   ```php
   activity()
       ->performedOn($deal)
       ->causedBy(auth()->user())
       ->withProperties([
           'old_warehouse_id' => $oldWarehouseId,
           'new_warehouse_id' => $newWarehouseId,
           'old_assigned_user_id' => $oldUserId,
           'new_assigned_user_id' => $newUserId,
           'reason' => $request->input('transfer_reason'),
       ])
       ->log('deal.branch_transferred');
   ```

---
*Next: Comprehensive API contracts and endpoint payloads are documented in `docs/crm/CRM_API_SPECIFICATION.md`.*
