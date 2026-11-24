# InoManage Cloud Multi-Tenant SaaS ERP
# CRM UI/UX & Component Specifications

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Frontend Interface Specification  
**Path:** `docs/crm/CRM_UI_UX_SPECIFICATION.md`

---

## 1. Design System & Frontend Architecture

The InoManage Cloud CRM frontend is built using **Vue 3.5 (Composition API, `<script setup>`)**, **Inertia.js**, and **Tailwind CSS**. It seamlessly integrates into the main application layout (`AppLayout.vue`) with consistent enterprise typography, accessibility standards (WCAG 2.1 AA), responsive breakpoints, and dark mode compatibility.

### Key Visual Language Constants
* **WhatsApp Channel:** Green badge (`bg-emerald-50 text-emerald-700 ring-emerald-600/20`).
* **SMS Channel:** Blue badge (`bg-blue-50 text-blue-700 ring-blue-600/20`).
* **Email Channel:** Indigo badge (`bg-indigo-50 text-indigo-700 ring-indigo-600/20`).
* **Deal Won:** Green status (`bg-emerald-100 text-emerald-800`).
* **Deal Lost:** Red status (`bg-rose-100 text-rose-800`).
* **SLA Breach Warning:** Amber pulse (`animate-pulse text-amber-600`).

---

## 2. Screen Specifications

### 2.1 Deal Pipeline Kanban Board (`resources/js/Pages/Crm/Deals/Kanban.vue`)

The Pipeline Kanban is the primary command center for sales teams.

```
+---------------------------------------------------------------------------------------------------------+
| CRM > Deals Pipeline     [ Pipeline: B2B Enterprise v ]   [ Branch: All Hubs v ]    [ + New Deal ]      |
+---------------------------------------------------------------------------------------------------------+
| Discovery (10%)        | Proposal Sent (40%)   | Negotiation (70%)     | Won (100%)                      |
| Total: BDT 450,000 (3) | Total: BDT 1.2M (4)   | Total: BDT 800,000 (2)| Total: BDT 2.5M (5)             |
+------------------------+-----------------------+-----------------------+---------------------------------+
| +--------------------+ | +-------------------+ | +-------------------+ | +-----------------------------+ |
| | Bengal Textile     | | | Apex Footwear Ltd | | | Dhaka Logistics   | | | Meghna Group                | |
| | 50 Barcode Readers | | | ERP Cloud Upgrade | | | Warehouse Tablets | | | 120 Thermal Printers        | |
| | BDT 150,000        | | | BDT 650,000       | | | BDT 400,000       | | | BDT 1,200,000               | |
| | Rep: Tanvir A.     | | | Rep: Farhana K.   | | | Rep: Tanvir A.    | | | Rep: Farhana K.             | |
| | Due: 15 Dec 2026   | | | Due: 28 Nov 2026  | | | Due: 02 Dec 2026  | | | [ Won: 21 Nov 2026 ]        | |
| +--------------------+ | +-------------------+ | +-------------------+ | +-----------------------------+ |
+------------------------+-----------------------+-----------------------+---------------------------------+
```

#### Key Interactions & UX Behaviors
* **Smooth Drag & Drop:** Built with HTML5 Drag & Drop or lightweight Vue draggable. Moving a card across stages triggers an optimistic UI update with an asynchronous `PUT /crm/deals/{id}/stage` request.
* **Deal Line Items Drawer:** Clicking on a deal card slides out a side drawer displaying `crm_deal_items` where products, quantities, and target unit prices can be edited before quotation creation.
* **Stage Probability Weights:** Column headers automatically recalculate stage totals and weighted pipeline values (`Sum(Value * Probability %)`).
* **Card Quick Actions:**
  * Open Deal Details Modal.
  * Generate Quotation (navigates directly to ERP Quotation builder with customer and items pre-filled).
  * Branch Transfer Modal (prompts for target active branch and audit reason).

---

### 2.2 Customer 360-Degree Cockpit (`resources/js/Pages/Crm/Customers/Show.vue`)

Integrates CRM relationship data with core ERP financial accounting.

```
+---------------------------------------------------------------------------------------------------------+
| [<- Back]  Apex Footwear Ltd (B2B Account)    [Customer Code: CUS-0084]    [ Edit ]  [ + New Activity ]  |
+---------------------------------------------------------------------------------------------------------+
| FINANCIAL SNAPSHOT                                                                                      |
| Total Invoiced: BDT 4,250,000  | Outstanding Balance: BDT 450,000 (DUE) | Credit Limit: BDT 1,000,000  |
+------------------------------------+--------------------------------------------------------------------+
| LEFT COLUMN: RELATIONSHIP INFO     | RIGHT COLUMN: UNIFIED ACTIVITY & TRANSACTION TIMELINE              |
|                                    |                                                                    |
| Primary Contact:                   | [ All ] [ Communications ] [ Quotations ] [ Invoices ] [ Cases ]   |
| Farhan Chowdhury (Procurement Dir) | ------------------------------------------------------------------ |
| Phone: +8801711223344              | 23 Nov 2026, 11:30 AM  [ WhatsApp Inbound ]                        |
| Email: farhan@apex.com             | "We have received the revised quotation and approved it."          |
|                                    | ------------------------------------------------------------------ |
| Secondary Contacts:                | 22 Nov 2026, 04:15 PM  [ ERP Quotation Created ]                   |
| - Salma Akter (Accounts Officer)   | Quotation #QTE-2026-0094 created by Tanvir Ahmed for BDT 650,000.  |
|                                    | ------------------------------------------------------------------ |
| Active Pipeline Deals:             | 20 Nov 2026, 02:00 PM  [ Support Case Resolved ]                   |
| - ERP Cloud Upgrade (BDT 650,000)  | Case #CAS-2026-0012 "Firmware patch for terminal" marked Resolved. |
|                                    | ------------------------------------------------------------------ |
| Branch Attribution:                | 15 Nov 2026, 10:00 AM  [ Outbound Phone Call ]                     |
| Tejgaon Distribution Hub           | Discussed deployment timeline with Farhan Chowdhury.               |
+------------------------------------+--------------------------------------------------------------------+
```

---

### 2.3 Unified Omnichannel Inbox (`resources/js/Pages/Crm/Inbox/Index.vue`)

Three-pane layout for high-throughput customer communications.

```
+---------------------------------------------------------------------------------------------------------+
| CRM Omnichannel Inbox              [ Filter: All Channels v ]  [ Status: Open v ]   [ Search Inbox... ] |
+------------------------------------+------------------------------------+-------------------------------+
| CONVERSATION LIST (Pane 1)         | ACTIVE CHAT THREAD (Pane 2)        | CONTEXT & ACTIONS (Pane 3)    |
+------------------------------------+------------------------------------+-------------------------------+
| [WA] Apex Footwear Ltd             | [WA] Farhan Chowdhury              | CUSTOMER SUMMARY              |
| Farhan: "We approved the quote..." | Online | Tejgaon Distribution Hub  | Apex Footwear Ltd             |
| 11:30 AM  [ 2 Unread ]             | ---------------------------------- | Due Balance: BDT 450,000      |
|                                    | 11:28 AM [Inbound - Farhan]        |                               |
| [SMS] Nasir Uddin                  | "Can you confirm if delivery can   | QUICK ACTIONS                 |
| Nasir: "Will visit branch tomorrow"| happen before Thursday?"           | [ + Create Deal ]             |
| Yesterday                          |                                    | [ + Generate Quote ]          |
|                                    | 11:29 AM [Outbound - Tanvir A.]    | [ + Open Support Case ]       |
| [Mail] Meghna Group                | "Yes, sir. Delivery is scheduled   |                               |
| Subject: RFP Clarification         | for Wednesday afternoon." (Read)   | RECENT TICKETS                |
| 20 Nov 2026                        |                                    | #CAS-0012: Terminal patch     |
|                                    | 11:30 AM [Inbound - Farhan]        | Status: Resolved              |
|                                    | "We approved the quote."           |                               |
|                                    | ---------------------------------- | LINKED DEALS                  |
|                                    | [ Type a reply or select template ]| ERP Cloud Upgrade             |
|                                    | [ + Attach PDF/Image ]  [ Send v ] | Value: BDT 650,000            |
+------------------------------------+------------------------------------+-------------------------------+
```

---

### 2.4 Marketing Campaign Wizard (`resources/js/Pages/Crm/Campaigns/Create.vue`)

Three-step guided stepper ensuring zero unauthorized promotional broadcasts.

1. **Step 1: Channel & Target Cohort:**
   * Channel selector cards: WhatsApp Business, BulkSMSBD, or Email.
   * Target Segment dropdown.
   * Real-time recipient counter querying dynamic segment size and automatically deducting suppressed/opted-out/DND numbers.
2. **Step 2: Message Composition & Personalization:**
   * Template body editor supporting variable tags: `{name}`, `{company}`, `{branch_name}`.
   * Pre-approved message template selector for WhatsApp dispatches.
   * Mandatory unsubscribe notice merge tag for SMS.
3. **Step 3: Dual-Custody Approval Submission:**
   * Recipient count, cost estimate, and scheduled time.
   * Mandatory disclaimers regarding telecommunication compliance.
   * Action button: **"Submit for Management Approval"** (moves to `pending_approval`).
   * *Approver View:* Distinct approval panel for Admins/Managers (`Approve` and `Reject` buttons with feedback notes).

---

### 2.5 Customer Support Workbench (`resources/js/Pages/Crm/Cases/Index.vue`)

* **List View:** Priority badges (`Urgent`, `High`, `Medium`, `Low`), SLA target countdown timers, assignee avatars.
* **Escalation to Workshop Modal (`EscalateToRepairModal.vue`):**
  * When a case requires physical hardware repair:
  * Modal pre-filled with Customer, Contact, and Case details.
  * Captures: Target Workshop Branch (`warehouse_id` where `type = 'service_center'`), Device Model, Serial Number, Physical Condition, Accessories Received.
  * Submitting creates an official ERP `ServiceTicket` and links `crm_support_cases.escalated_service_ticket_id`.

---
*Next: Multi-channel telecommunications architecture is detailed in `docs/crm/CRM_COMMUNICATION_ARCHITECTURE.md`.*
