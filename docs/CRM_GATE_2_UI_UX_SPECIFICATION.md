# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
# STAGE 2 CRM — GATE 2 UI/UX SPECIFICATION
## Frontend Architecture, Page Inventory, Screen Specifications, and Component Patterns

**Project:** InoManage Cloud Multi-Tenant SaaS ERP  
**Repository:** `D:\Project\Saas_inventory`  
**Current Branch:** `crm/gate-1-schema`  
**Accepted Gate 1 HEAD:** `a0cd9941ffcfae6e603e0e31c8a5674916442dd4`  
**Document Status:** DRAFT FOR PROJECT OWNER APPROVAL (REVISION 2.2 — REFINED)  
**Implementation Authorization:** NOT AUTHORIZED — PLANNING ONLY  

---

## 1. UI/UX DESIGN SYSTEM & ARCHITECTURAL FOUNDATION

The CRM frontend strictly leverages the existing InoManage multi-tenant SaaS frontend architecture.

### 1.1 Technology Stack & Guidelines
- **Core Framework:** Vue 3.5+ using the Composition API with `<script setup>`.
- **Client Routing & Data Binding:** Inertia.js 3.9+ via `@inertiajs/vue3`.
- **Styling & Design System:** Tailwind CSS 3.4+ with `@tailwindcss/forms` and `@tailwindcss/typography`.
- **Iconography:** Lucide Icons (`lucide-vue-next`).
- **Data Visualizations:** ApexCharts (`apexcharts` and `vue3-apexcharts`).
- **Color Palette & Theme:** Slate/Zinc dark sidebar navigation with Indigo primary accents (`bg-indigo-600`, `text-indigo-600`, `border-indigo-600`), Emerald for success/won states, Rose/Red for lost/overdue states, and Amber for warnings/pending approvals.
- **Strict Prohibition:** No secondary design frameworks, React, external component libraries (e.g., Vuetify, Element Plus), or standalone SPAs shall be introduced.

---

## 2. NAVIGATION & MENU HIERARCHY

CRM navigation is directly integrated into `resources/js/Layouts/AppLayout.vue` under a collapsable, permission-aware section titled **"Sales & CRM"**.

```
[Sidebar Navigation: Sales & CRM]
├── POS Terminal             (pos.index - Existing ERP)
├── Quotations & Estimates   (quotations.index - Existing ERP)
├── Sales & Dispatches       (sales.index - Existing ERP)
├── -----------------------  [CRM Core Section]
├── CRM Command Center       (crm.dashboard)
├── Leads Management         (crm.leads.index)
├── Accounts & Customers     (crm.customers.index)
├── Sales Deals & Pipelines  (crm.deals.index / crm.deals.kanban)
├── Activities & Tasks       (crm.activities.index)
├── -----------------------  [ERP Operational]
├── Delivery Challans        (challans.index - Existing ERP)
├── Customer Due Ledger      (dues.index - Existing ERP)
└── Returns & RMA            (returns.index - Existing ERP)
```

---

## 3. COMPLETE PAGE INVENTORY

| Page ID | Route Name | URL Pattern | Inertia Component Path | Key Capabilities |
| :--- | :--- | :--- | :--- | :--- |
| **CRM-PG-01** | `crm.dashboard` | `/crm/dashboard` | `Pages/Crm/Dashboard.vue` | KPI metrics, pipeline funnel, overdue tasks, rep leaderboard |
| **CRM-PG-02** | `crm.leads.index` | `/crm/leads` | `Pages/Crm/Leads/Index.vue` | Filterable lead list, search, status tabs, bulk actions |
| **CRM-PG-03** | `crm.leads.create` | `/crm/leads/create` | `Pages/Crm/Leads/Create.vue` | Lead creation form with source, branch, rep assignment |
| **CRM-PG-04** | `crm.leads.show` | `/crm/leads/{lead}` | `Pages/Crm/Leads/Show.vue` | Lead detail, activity timeline, quick notes, conversion modal |
| **CRM-PG-05** | `crm.leads.edit` | `/crm/leads/{lead}/edit` | `Pages/Crm/Leads/Edit.vue` | Lead editing with custom fields |
| **CRM-PG-06** | `crm.customers.index` | `/crm/customers` | `Pages/Crm/Customers/Index.vue` | Customer list with B2B/B2C filter, contact count, due status |
| **CRM-PG-07** | `crm.customers.360` | `/crm/customers/{customer}/360` | `Pages/Crm/Customers/Show360.vue` | Consolidated 360 profile (contacts, deals, orders, quotes, ledger) |
| **CRM-PG-08** | `crm.deals.index` | `/crm/deals` | `Pages/Crm/Deals/Index.vue` | Tabular deal view, stage filter, monetary value aggregation |
| **CRM-PG-09** | `crm.deals.kanban` | `/crm/deals/kanban` | `Pages/Crm/Deals/Kanban.vue` | Interactive multi-pipeline drag-and-drop Kanban board |
| **CRM-PG-10** | `crm.deals.create` | `/crm/deals/create` | `Pages/Crm/Deals/Create.vue` | Deal creation with customer select, pipeline, stage, items |
| **CRM-PG-11** | `crm.deals.show` | `/crm/deals/{deal}` | `Pages/Crm/Deals/Show.vue` | Deal progression bar, product items table, quotes, loss modal |
| **CRM-PG-12** | `crm.deals.edit` | `/crm/deals/{deal}/edit` | `Pages/Crm/Deals/Edit.vue` | Deal edit form |
| **CRM-PG-13** | `crm.activities.index` | `/crm/activities` | `Pages/Crm/Activities/Index.vue` | Unified task agenda, calls, meetings, completion toggle |
| **CRM-PG-14** | `crm.pipelines.index` | `/crm/pipelines` | `Pages/Crm/Pipelines/Index.vue` | Pipeline and stage configuration management |

---

## 4. DETAILED SCREEN SPECIFICATIONS

### 4.1 Accounts & Customers (`Pages/Crm/Customers/Index.vue`)
- **Classification Header Toggle:** Allows instant filtering between:
  - `All Customers` (Consolidated view)
  - `B2B Company Accounts` (Evaluated via compound heuristic: `company_name != ''` OR `tax_number != ''` OR `crm_contacts_count > 0`)
  - `B2C Individual Customers` (Individual retail consumers)
- **Data Table Elements:**
  - Customer / Company Name (highlights business trade name vs company name).
  - Classification Badge: `B2B Account` (Indigo badge) vs `B2C Customer` (Slate badge).
  - Tax / BIN Number (displayed when available for B2B entities).
  - Stakeholder Count Badge: Indicates registered contact persons with one-click popover preview.
  - Authoritative Due Balance: Formatted with `CurrencyDisplay.vue`, styled in amber/rose for unpaid balances and green for fully settled accounts.
  - Actions: `View 360`, `Add Stakeholder Contact`, `Create Deal`.

### 4.2 Customer 360 View (`Pages/Crm/Customers/Show360.vue`)
- **Header Profile Card:**
  - Displays customer identity, verified classification (`B2B Account` vs `B2C`), TIN/BIN number, billing address.
  - Authoritative Financial Balance Card: Displays `Total Billed`, `Total Collected`, and `Net Outstanding Due`.
    - Integrated directly with ERP sales order ledger, accurately accounting for `INV-OPEN-xxxx` opening invoices and payments without double-counting.
    - Includes button: "Record Due Payment" linking directly to `route('dues.index', { search: customer.phone })`.
- **Tabbed Subsystems:**
  1. *Stakeholders & Contacts Tab:* Full listing of B2B contact persons, designations, departments, primary flags, and inline "Add Stakeholder" modal.
  2. *Deals & Pipelines Tab:* Active deals, current pipeline stages, expected close dates, and contract values.
  3. *Sales Orders & Invoices Tab:* Real-time ERP sales orders with fulfillment status and paid/due balances.
  4. *Estimates & Quotations Tab:* ERP quotations with quotation status and items summary.
  5. *Activity History Tab:* Reverse-chronological timeline aggregating notes, calls, meetings, and follow-ups across the account and its child deals.

### 4.3 Leads Conversion Modal with Duplicate Protection (`LeadConvertModal.vue`)
- **Proactive Duplicate Detection Banner:**
  - When opened, queries tenant records for matching phone or email.
  - If a match is found: Displays an amber alert: *"Existing customer found matching this phone number ([Customer Name]). Converting will link this lead to the existing customer master without creating a duplicate."*
  - If no match: Displays emerald notice: *"New customer record will be created in the canonical ERP customer master."*
- **Conversion Options:**
  - Option to create an initial Deal linked to the new customer.
  - Option to register the prospective contact person as the primary B2B stakeholder in `crm_contacts`.

---

## 5. REUSABLE VUE COMPONENT INVENTORY & PATTERNS

| Component Name | Source Path | Props / Contract | Purpose in CRM |
| :--- | :--- | :--- | :--- |
| `StatusBadge.vue` | `resources/js/Components/StatusBadge.vue` | `status: String`, `variant: String` | Formats badges for lead status, deal status, won/lost flags |
| `Pagination.vue` | `resources/js/Components/Pagination.vue` | `links: Array` | Standard Inertia pagination across all CRM index views |
| `Modal.vue` | `resources/js/Components/Modal.vue` | `show: Boolean`, `maxWidth: String`, `closeable: Boolean` | Base dialog overlay for modals |
| `ConfirmationModal.vue`| `resources/js/Components/ConfirmationModal.vue` | `show: Boolean`, `@close`, `@confirm` | Destructive confirmations (delete lead, cancel deal) |
| `SearchableSelect.vue` | `resources/js/Components/SearchableSelect.vue` | `modelValue`, `options: Array`, `placeholder: String` | Async customer search, product picker, warehouse selector |
| `ActionDropdown.vue` | `resources/js/Components/ActionDropdown.vue` | `align: String`, `width: String` | 3-dot dropdown menu on table rows |
| `CurrencyDisplay.vue` | `resources/js/Components/CurrencyDisplay.vue` | `amount: Number`, `currency: String` | Formats currency values in BDT with proper comma grouping |
| `DateDisplay.vue` | `resources/js/Components/DateDisplay.vue` | `date: String`, `format: String` | Standardizes date/time formatting |
| `LoadingSpinner.vue` | `resources/js/Components/LoadingSpinner.vue` | `size: String`, `color: String` | Visual spinner for async form submissions |
