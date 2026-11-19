# -*- coding: utf-8 -*-
"""
Sections 32 to 40 of ERP System Design Document
"""

CONTENT = r"""
---

## 32. PLAN AND SUBSCRIPTION ARCHITECTURE

### 32.1 Target Subscription Engine Lifecycle
The target subscription engine resolves the shortcomings of in-place plan mutations by introducing versioned plan definitions and automated state transitions:

```
[Tenant Registration] ──> [3-Day Free Trial Provisioned]
                                     │
                 ┌───────────────────┴───────────────────┐
                 ▼                                       ▼
        [Trial Active]                          [Trial Expired]
                 │                                       │
                 ▼ (Selects Plan)                        ▼ (Paywall Triggered)
      [Submits MFS TrxID / SSLCommerz]           [Restricted to /subscription]
                 │                                       │
                 ▼                                       ▼
       [Super-Admin Approves] ─────────────────> [Active Subscription]
                                                         │
                                                         ▼ (30 Days / 365 Days)
                                                 [Renewal Period (-7 Days)]
                                                         │
                                    ┌────────────────────┴────────────────────┐
                                    ▼                                         ▼
                            [Payment Confirmed]                       [Unpaid at Expiry]
                                    │                                         │
                                    ▼                                         ▼
                        [Subscription Extended]                        [3-Day Grace Period]
                                                                              │
                                                                              ▼
                                                                     [Workspace Suspended]
```

### 32.2 Proposed Schema Additions
```sql
CREATE TABLE plan_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    name VARCHAR(100) NOT NULL,
    price_bdt DECIMAL(12, 2) NOT NULL,
    annual_price_bdt DECIMAL(12, 2) NOT NULL,
    max_products INT NOT NULL,
    max_warehouses INT NOT NULL,
    max_users INT NOT NULL,
    max_quotations INT NOT NULL,
    max_sales_orders INT NOT NULL,
    max_purchase_orders INT NOT NULL,
    max_projects INT NOT NULL,
    max_employees INT NOT NULL,
    features JSON NOT NULL,
    is_current BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
);
```

---

## 33. ACCOUNTING & FINANCE ARCHITECTURE (UNIFIED GENERAL LEDGER)

### 33.1 Reconciliation and Unification Strategy
The target accounting design eliminates the dual system (`financial_accounts` vs `chart_of_accounts`) by integrating Bank and Cash repositories directly into the Chart of Accounts:
1. **Designated Asset Accounts:** Petty Cash (`COA #1010`), Prime Bank (`COA #1020`), bKash Merchant (`COA #1021`), and Nagad Merchant (`COA #1022`) are created as child records under Current Assets (`COA #1000`).
2. **Double-Entry Journal Postings:** Every operational transaction dispatches a structured event handled by `AccountingPostingService`:
   - **Cash Sale:**
     - `DEBIT:` Cash in Hand (`#1010`) / Bank (`#1020`) — `[Total Paid]`
     - `DEBIT:` Accounts Receivable (`#1030`) — `[Due Amount]`
     - `DEBIT:` Cost of Goods Sold (`#5010`) — `[Inventory Cost]`
     - `CREDIT:` Sales Revenue (`#4010`) — `[Net Subtotal]`
     - `CREDIT:` VAT Payable Mushak 6.3 (`#2030`) — `[Tax Amount]`
     - `CREDIT:` Inventory Merchandise (`#1040`) — `[Inventory Cost]`
   - **Procurement Goods Receipt:**
     - `DEBIT:` Inventory Merchandise (`#1040`) — `[Goods Cost]`
     - `DEBIT:` Input VAT Receivable (`#1060`) — `[Input VAT Paid]`
     - `CREDIT:` Accounts Payable (`#2010`) — `[Gross Invoiced]`
   - **Salary Disbursement:**
     - `DEBIT:` Staff Salaries & Wages (`#5020`) — `[Gross Salary]`
     - `CREDIT:` Tax Deducted at Source TDS (`#2050`) — `[Income Tax Deducted]`
     - `CREDIT:` Bank Operating Account (`#1020`) — `[Net Disbursed]`

---

## 34. INVENTORY & SUPPLY CHAIN ARCHITECTURE

### 34.1 Multi-Warehouse Topology & Stock Valuation
- **Inventory Valuation Methods:** Support for Weighted Average Cost (WAC) as system default and First-In First-Out (FIFO) lot tracking for serialized and batched SKUs.
- **Stock Movement Ledger:** All quantity adjustments enforce immutable ledger logging (`stock_movements`) before updating aggregate warehouse quantities in `stock_levels`.
- **Serial & IMEI Lifecycle:**
  - States: `in_stock` -> `allocated_to_order` -> `dispatched` -> `in_service` -> `returned_to_stock` -> `scrapped`.
  - Serial records maintain direct foreign keys to `warehouse_id`, `purchase_order_id`, and `sales_order_id` for end-to-end warranty tracking.

---

## 35. SALES & POINT OF SALE (POS) ARCHITECTURE

### 35.1 Quotation, Order, and Invoicing Pipeline
1. **Quotation Stage:** Price negotiation and validity duration tracking. No stock is reserved.
2. **Sales Order Confirmation:** Order confirmed; stock reserved (`stock_levels.reserved_quantity += quantity`, `available_quantity -= quantity`) to prevent concurrent counter overselling.
3. **Fulfillment / Dispatch:** Warehouse staff fulfill order; stock formally deducted from physical warehouse (`quantity -= quantity`, `reserved_quantity -= quantity`). Delivery Challan generated.
4. **Counter POS Acceleration:** POS combines Confirmation, Dispatch, and Money Receipt into a single atomic database transaction.

---

## 36. HUMAN RESOURCE MANAGEMENT & PAYROLL ARCHITECTURE

### 36.1 Enterprise Employee Directory & Compensation
- **Salary Components:**
  - Fixed Allowances: Basic Salary (typically 50-60%), House Rent (20-25%), Medical Allowance (10%), Conveyance Allowance.
  - Variable Adjustments: Overtime, Performance Bonuses, Festival Bonus (Eid/Puja).
  - Statutory Deductions: Employee Provident Fund (PF), Tax Deducted at Source (TDS), Loan installments.
- **Automated Payroll Sheet Generation:** Generates monthly payroll sheets across selected departments with one-click bank disbursement file export (Excel / BEFTN format).

---

## 37. BANGLADESH VAT & COMPLIANCE ARCHITECTURE

### 37.1 Statutory NBR Document Suite
Under the Bangladesh Value Added Tax and Supplementary Duty Act 2012, registered businesses must issue and maintain statutory VAT documents:

| Form | NBR Designation | Functional Purpose | Triggering Event in ERP |
|---|---|---|---|
| **Mushak 6.3** | Tax Invoice | Mandatory commercial tax invoice issued at time of supply | Sales Order / POS Checkout completion |
| **Mushak 6.5** | Transfer Challan | Goods transfer between branches or centralized warehouses | Inter-warehouse inventory transfers |
| **Mushak 6.6** | Withholding Certificate | Certificate of VAT deducted at source from supplier bills | Vendor payment settlement with VDS deduction |
| **Mushak 6.7** | Credit Note | Adjustment note issued for customer goods returns or rebates | Customer sales return approval |
| **Mushak 6.8** | Debit Note | Adjustment note issued for supplier returns or purchase rebates | Supplier purchase return approval |
| **Mushak 9.1** | Monthly VAT Return | Comprehensive monthly return submitted to NBR commissionerate | End-of-month regulatory report generation |

### 37.2 Proposed Mushak 6.3 Schema Definition
```sql
CREATE TABLE mushak_6_3_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    sales_order_id BIGINT UNSIGNED NOT NULL,
    challan_number VARCHAR(100) NOT NULL UNIQUE,
    issue_date DATE NOT NULL,
    issue_time TIME NOT NULL,
    seller_name VARCHAR(255) NOT NULL,
    seller_bin VARCHAR(50) NOT NULL,
    buyer_name VARCHAR(255) NOT NULL,
    buyer_bin VARCHAR(50) NULL,
    destination_address TEXT NOT NULL,
    vehicle_type VARCHAR(100) NULL,
    vehicle_number VARCHAR(100) NULL,
    total_taxable_value DECIMAL(15, 2) NOT NULL,
    total_sd_amount DECIMAL(15, 2) DEFAULT 0.00,
    total_vat_amount DECIMAL(15, 2) NOT NULL,
    printed_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE
);
```

---

## 38. SECURITY ARCHITECTURE & REMEDIATION BLUEPRINT

### 38.1 Remediation Actions for Identified Vulnerabilities
1. **Remediation for SEC-001 (Hardcoded Super-Admin Credentials):**
   - Purge `Database\Seeders\UserAndTenantSeeder.php` of plaintext passwords.
   - Implement dynamic environment initialization (`SUPER_ADMIN_PASSWORD=${APP_INITIAL_KEY}`).
2. **Remediation for SEC-002 (Missing Route Authorization):**
   - Wrap all domain resource routes in `routes/web.php` with `can:` middleware.
   - Enforce `$this->authorizeResource(Product::class)` across resource controllers.
3. **Remediation for SEC-003 (Tenant Leakage in Asynchronous Jobs):**
   - Create a base `TenantAwareJob` abstract class that captures and binds the tenant execution context.

---

## 39. INFRASTRUCTURE & DEVOPS ARCHITECTURE

### 39.1 Containerized Production Topology
The target production deployment utilizes Docker containers orchestrated via Docker Compose or Kubernetes:
- `app`: PHP-FPM 8.2 container running Laravel monolith.
- `web`: Nginx 1.24 container handling static files and fastcgi proxying.
- `queue`: Dedicated worker container running `php artisan queue:work --tries=3 --timeout=90`.
- `scheduler`: Lightweight cron container running `php artisan schedule:run` every minute.
- `db`: Managed MariaDB 10.6 / MySQL 8.0 instance with automated daily snapshots.
- `cache`: Redis 7.2 cluster handling sessions, cache tags, and queue jobs.
- `storage`: S3-compatible MinIO or AWS S3 bucket for PDF storage and tenant logo uploads.

### 39.2 CI/CD Deployment Pipeline
```
[Git Push to 'main'] ──> [GitHub Actions Runner]
                               │
            ┌──────────────────┴──────────────────┐
            ▼                                     ▼
   [Linting & Static Analysis]            [Automated Test Suite]
   - PHP Pint formatting                  - PHPUnit Feature Tests
   - PHPStan Level 8                      - Tenant Isolation Tests
   - ESLint & Vue-TSC                     - Multi-Tenant RBAC Tests
            │                                     │
            └──────────────────┬──────────────────┘
                               ▼ (Tests Pass)
                [Build & Push Docker Images]
                               │
                               ▼
                [Zero-Downtime Rolling Deploy]
                - Atomic migrations (`artisan migrate --force`)
                - Cache optimization (`config:cache`, `route:cache`)
                - PHP-FPM reload & queue restart
```

---

## 40. PERFORMANCE & SCALABILITY BLUEPRINT

### 40.1 Database Query Optimization & Indexing
- **Resolution of N+1 Queries:** Eager load related customer, warehouse, and item relations (`with(['customer', 'warehouse', 'items.product'])`) on sales orders and purchase orders.
- **Composite Indexing:** Add composite indexes `(team_id, created_at)` and `(team_id, status)` across large transaction tables (`sales_orders`, `purchase_orders`, `stock_movements`, `journal_entries`).
- **Read-Write Splitting:** Configure Laravel database connections for Read/Write replicas when database size exceeds 500 GB or concurrent users exceed 1,000.
"""
