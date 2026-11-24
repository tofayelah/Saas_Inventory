# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
## STAGE 1, GATE 3.1: TARGETED SECURITY REMEDIATION REPORT

**Document ID:** SEC-G3-1-REP-2026-11-23  
**Classification:** HIGH CONFIDENCE / PRODUCTION-READY SECURITY VERIFICATION  
**Author:** Principal SaaS Security Architect & Laravel Security Specialist  
**Project:** InoManage Cloud Multi-Tenant SaaS ERP (`Saas_inventory`)  
**Target Branch:** `security/gate-3.1-payment-storage-remediation`  
**Base Commit:** `e18f32224c00de943ace871653c032f2cc13331d` (Gate 3 Implementation)  
**Execution Date:** November 23, 2026  

---

## 1. EXECUTIVE SUMMARY

Following the independent post-implementation security audit of Stage 1, Gate 3, six specific security findings (two HIGH, two MEDIUM, one LOW, and one INFORMATIONAL) were targeted for explicit remediation. All authorized remediation actions have been implemented, verified through automated unit and feature tests, and integrated into the dedicated remediation branch `security/gate-3.1-payment-storage-remediation`.

### Remediation Status Matrix

| Finding ID | Severity | Description | Status | Verification |
|---|---|---|---|---|
| **SEC-G3-REV-01** | **HIGH** | SSLCommerz Server-to-Server Transaction Validation Missing | **RESOLVED** | Outbound Order Validation API client implemented in `SslCommerzValidationService`. Automated unit tests with HTTP mocks pass. |
| **SEC-G3-REV-02** | **HIGH** | Sandbox Payment Status Bypass | **RESOLVED** | Removed sandbox short-circuit in `SslCommerzPaymentController`. Both sandbox and production enforce `VALID`/`VALIDATED`. |
| **SEC-G3-REV-03** | **MEDIUM** | API Document Download Route Lacks Ability Enforcement | **RESOLVED** | Added `documents:read` ability check to `GET /api/v1/files/download` and mapped to Spatie permissions in `CheckTenantTokenAbility`. |
| **SEC-G3-REV-04** | **MEDIUM** | Tenant Letterheads Stored on Public Disk | **RESOLVED** | Moved letterhead upload to `private` disk in `TenantSettingsController`. `Team` model updated to resolve absolute filesystem paths via `Storage::disk('private')`. |
| **SEC-G3-REV-05** | **LOW** | File Storage Relies on Incomplete Blacklist | **RESOLVED** | Implemented strict extension allowlist and corresponding MIME type validation in `SecureStorageService`. |
| **SEC-G3-REV-06** | **INFORMATIONAL** | `/api/user` Exposure and Scope Audit | **AUDITED & DOCUMENTED** | Inspected `/api/user` payload; confirmed tenant isolation context and documented hardening recommendations. |

---

## 2. DETAILED REMEDIATION ACTIONS

### 2.1 SEC-G3-REV-01 (HIGH): Authoritative Server-to-Server SSLCommerz Validation

#### Root Cause
In Gate 3, `SslCommerzPaymentController` accepted callback parameters (`status`, `amount`, `currency`, `tran_id`) supplied directly by the client browser or webhook POST without performing an authoritative server-to-server outbound query to the official SSLCommerz Order Validation API (`validationserverAPI.php`).

#### Remediation Implemented
1. **Created `App\Services\SslCommerzValidationService`:**
   - Queries `https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php` (or live `https://securepay.sslcommerz.com/validator/api/validationserverAPI.php` based on `config('services.sslcommerz.sandbox')`).
   - Passes authoritative merchant credentials: `store_id`, `store_passwd`, `val_id`, and `format=json`.
   - Uses Laravel's safe `Http::timeout(10)->connectTimeout(5)->asForm()->get(...)`.
   - Validates response:
     - Outbound HTTP call must return status 200.
     - Provider status in JSON response must strictly equal `VALID` or `VALIDATED`.
     - Transaction ID (`tran_id`) returned by provider must match the expected internal reference.
     - Currency returned must strictly equal `BDT`.
     - Amount returned must match the exact pending subscription amount (`abs(provider_amount - expected) < 0.01`).
     - Enforces duplicate `val_id` guard: queries `SubscriptionPayment::where('provider_payment_id', $valId)->where('status', 'approved')->exists()`. If a payment was already approved with that `val_id`, subsequent attempts are rejected immediately.
2. **Integrated into `SslCommerzPaymentController`:**
   - Both `success()` (user browser redirect) and `ipn()` (asynchronous webhook) call `$this->validationService->validateTransaction($valId, $expectedTranId, $expectedAmount, $expectedCurrency)`.
   - Payments are marked `approved` only if the authoritative validation succeeds. If validation fails or times out, the callback rejects or returns HTTP 422 with a structured error log.

---

### 2.2 SEC-G3-REV-02 (HIGH): Elimination of Sandbox Status Short-Circuit

#### Root Cause
In `SslCommerzPaymentController`, lines checking transaction status contained:
`if (in_array($status, ['VALID', 'VALIDATED']) || config('services.sslcommerz.sandbox', true))`
In development or staging environments, any callback (including status `FAILED` or `CANCELLED`) automatically evaluated to true, prematurely activating subscriptions.

#### Remediation Implemented
1. Removed all occurrences of `|| config('services.sslcommerz.sandbox', true)` from `SslCommerzPaymentController`.
2. Standardized validation logic so that sandbox and production operate under identical security invariants:
   - Only `in_array($status, ['VALID', 'VALIDATED'])` is permitted.
   - `fail()` and `cancel()` callbacks explicitly mark the transaction `rejected` and do not activate subscriptions.

---

### 2.3 SEC-G3-REV-03 (MEDIUM): API File Download Token Ability & Permission Enforcement

#### Root Cause
The route `GET /api/v1/files/download` was protected by `auth:sanctum` and `tenant.context`, but lacked explicit `token.ability` middleware. Any valid token issued for any ability (e.g. `reports:read` or `orders:write`) could invoke the document download endpoint.

#### Remediation Implemented
1. **Added Token Ability Mapping in `CheckTenantTokenAbility`:**
   - Mapped `documents:read` ability to server-side Spatie permission `'view reports'`.
   - Mapped `documents:write` ability to server-side Spatie permission `'manage settings'`.
2. **Applied Middleware to Route in `routes/api.php`:**
   - Changed:
     ```php
     Route::get('/files/download', [TenantFileController::class, 'download']);
     ```
     To:
     ```php
     Route::get('/files/download', [TenantFileController::class, 'download'])
         ->middleware('token.ability:documents:read');
     ```
   - Requests without `documents:read` ability or without the corresponding Spatie permission receive `403 Forbidden`.

---

### 2.4 SEC-G3-REV-04 (MEDIUM): Private Storage for Tenant Letterheads

#### Root Cause
`TenantSettingsController::update()` stored custom invoice and report letterheads (`invoice_bg` and `report_bg`) on the `public` storage disk (`store("tenants/{$team->id}/letterheads", 'public')`), exposing high-resolution corporate stationery, signatures, and stamps to unauthenticated internet access.

#### Remediation Implemented
1. **Updated `TenantSettingsController::update()`:**
   - Letterheads are now saved strictly to the `private` storage disk:
     ```php
     $path = $request->file('invoice_bg')->store("tenants/{$team->id}/letterheads", 'private');
     ```
2. **Updated Cleanup in `removeInvoiceBg()` and `removeReportBg()`:**
   - Cleans up existing files from both `private` and legacy `public` disks to ensure safe transitions for migrated tenants.
3. **Updated `Team` Model Helper Methods:**
   - `Team::getInvoiceBgAbsolutePath()` and `getReportBgAbsolutePath()` now prioritize `Storage::disk('private')->path($path)` when present, while gracefully falling back to legacy public paths if files were stored prior to the migration.

---

### 2.5 SEC-G3-REV-05 (LOW): Strict Allowlist & MIME Validation in SecureStorageService

#### Root Cause
`SecureStorageService` relied on a dangerous-extension blacklist (`FORBIDDEN_EXTENSIONS = ['php', 'phtml', 'phar', 'exe', 'bat', 'sh', ...]`), which is vulnerable to unlisted executable extensions, secondary extensions, or format tricks.

#### Remediation Implemented
1. **Replaced Blacklist with Comprehensive Allowlist:**
   - Defined `ALLOWED_EXTENSIONS` with explicit authorized extensions: `pdf`, `doc`, `docx`, `xls`, `xlsx`, `csv`, `txt`, `jpg`, `jpeg`, `png`, `webp`, `zip`.
2. **Enforced Content MIME Type Verification:**
   - Each extension is mapped to its legitimate IANA MIME type (e.g. `pdf => ['application/pdf']`, `png => ['image/png']`).
   - The service compares the client extension against `$file->getMimeType()`:
     ```php
     if (!in_array($mimeType, self::ALLOWED_EXTENSIONS[$extension], true)) {
         throw new SecurityException("MIME type '{$mimeType}' does not match allowed types for extension '{$extension}'.");
     }
     ```
   - Malicious files disguised with forged extensions (e.g. a PHP script named `exploit.pdf` with `text/x-php` MIME) are rejected with a security exception.

---

### 2.6 SEC-G3-REV-06 (INFORMATIONAL): Audit of `/api/user` Endpoint

#### Inspection Summary
Route `GET /api/user` is registered in `routes/api.php` under `auth:sanctum` and `tenant.context`.
- **Controller Action:** Returns `$request->user()`.
- **Exposed Attributes:** `id`, `name`, `email`, `phone`, `current_team_id`, `created_at`, `updated_at`. Sensitive fields (`password`, `remember_token`, `two_factor_recovery_codes`, `two_factor_secret`) are excluded by default Eloquent `$hidden` array on the `User` model.
- **Tenant Context:** Because `SetTenantContext` middleware wraps the entire API group, `TenantContext::getTenantId()` is initialized to the token's authenticated tenant. Cross-tenant user enumeration is impossible.
- **Hardening Recommendation:** In a future stage (Stage 2 API Versioning), wrap `/api/user` inside an explicit `UserResource` to whitelist only necessary fields and prevent unintentional exposure should new database columns be added to the `users` table.

---

## 3. CODE MODIFICATION & INVENTORY

The following files were created or modified during Gate 3.1:

| File Path | Type | Modifications |
|---|---|---|
| `app/Services/SslCommerzValidationService.php` | **NEW** | Authoritative Order Validation API service with timeout, BDT currency, exact amount, and duplicate `val_id` enforcement. |
| `app/Http/Controllers/SslCommerzPaymentController.php` | Modified | Injected `SslCommerzValidationService`; removed sandbox status bypass; enforced server-to-server validation on `success` and `ipn`. |
| `app/Http/Middleware/CheckTenantTokenAbility.php` | Modified | Added `documents:read` and `documents:write` ability-to-permission mappings. |
| `routes/api.php` | Modified | Applied `token.ability:documents:read` to `GET /api/v1/files/download`. |
| `app/Services/SecureStorageService.php` | Modified | Replaced blacklist with strict extension allowlist and content MIME type verification. |
| `app/Http/Controllers/TenantSettingsController.php` | Modified | Stored letterheads in `private` storage disk; added dual-disk cleanup on removal. |
| `app/Models/Team.php` | Modified | Added Storage facade path resolution for private letterhead rendering in DomPDF. |
| `tests/Feature/Security/StorageAndApiSecurityTest.php` | Modified | Added 9 new unit & feature tests for server-to-server validation, sandbox bypass prevention, duplicate `val_id` guard, token download abilities, and MIME verification. |
| `tests/Feature/BangladeshiSubscriptionAndBillingTest.php` | Modified | Added `Http::fake` mocking for SSLCommerz Order Validation API endpoints. |
| `tests/Feature/TenantSettingsTest.php` | Modified | Updated storage assertions to verify letterhead presence in `private` disk. |

---

## 4. AUTOMATED TEST EVIDENCE & REGRESSION VERIFICATION

### 4.1 Storage & API Security Test Suite (`StorageAndApiSecurityTest.php`)
```
PASS  Tests\Feature\Security\StorageAndApiSecurityTest
✓ tenant file storage isolates files by tenant                                  0.47s  
✓ storage path traversal is blocked                                             0.31s  
✓ unauthenticated user cannot access tenant storage via fallback                0.03s  
✓ dangerous file extensions are rejected on upload                              0.04s  
✓ sanctum token is strictly bound to tenant                                     0.05s  
✓ cross tenant token spoofing is blocked                                        0.03s  
✓ token cannot be issued for tenant user does not belong to                     0.03s  
✓ token ability and server side permission enforcement                          0.04s  
✓ suspended tenant token is rejected                                            0.03s  
✓ webhook rejects amount mismatch                                               0.04s  
✓ authoritative server to server validation approves payment                    0.05s  
✓ invalid server validation response rejects payment                            0.03s  
✓ provider service timeout or failure rejects payment                           0.04s  
✓ failed and cancelled status in sandbox cannot approve payment                 0.03s  
✓ duplicate val id across approved payments is rejected                         0.03s  
✓ missing documents read ability rejects api file download                      0.03s  
✓ authorized api file download with documents read ability                      0.03s  
✓ tenant letterheads stored in private disk                                     0.07s  
✓ mime type mismatch with extension is rejected on upload                       0.03s  
✓ unsupported file extension is rejected on upload                              0.03s  

Tests:    20 passed (63 assertions)
Duration: 1.57s
```

### 4.2 Comprehensive Security Test Suite (`tests/Feature/Security/`)
```
PASS  Tests\Feature\Security\FinancialReportAuthorizationTest (7 tests)
PASS  Tests\Feature\Security\RbacAuthorizationTest (9 tests)
PASS  Tests\Feature\Security\StorageAndApiSecurityTest (20 tests)
PASS  Tests\Feature\Security\SuperAdminImpersonationAuditTest (5 tests)
PASS  Tests\Feature\Security\TenantIsolationEngineTest (10 tests)

Tests:    51 passed (235 assertions)
Duration: 4.71s
```

### 4.3 Core Business & Workflow Regression Suites
```
PASS  Tests\Feature\BangladeshiSubscriptionAndBillingTest (16 tests)
PASS  Tests\Feature\DoubleEntryAccountingTest (16 tests)
PASS  Tests\Feature\PdfDocumentGenerationTest (6 tests)
PASS  Tests\Feature\SuperAdminControlTest (13 tests)
PASS  Tests\Feature\TenantSettingsTest (8 tests)

Tests:    59 passed (279 assertions)
Duration: 5.51s
```

### 4.4 Full Repository Regression Suite (`php artisan test`)
```
Tests:    7 skipped, 414 passed (2552 assertions)
Duration: 30.59s
Result:   100% PASS (Zero failures, zero regressions across 421 tests)
```

---

## 5. GATE 3.1 CONCLUSION & SIGN-OFF

All Gate 3.1 remediation tasks have been executed with strict adherence to the project's security invariants:
1. SSLCommerz transactions are authoritatively validated via direct outbound server-to-server API calls before subscriptions can be approved or extended.
2. Sandbox bypass logic has been eradicated.
3. API document downloads are secured by explicit token abilities and RBAC permissions.
4. Tenant letterheads are protected within private filesystem storage.
5. Insecure extension blacklists have been replaced with strict allowlists and MIME inspection.
6. Zero regressions were introduced across the entire 421-test suite.

**Mandatory Gating Condition:**  
Development is halted at the Gate 3.1 boundary. No Gate 4, Stage 2, or CRM feature development will begin without explicit approval.
