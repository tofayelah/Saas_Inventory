# INOMANAGE CLOUD MULTI-TENANT SAAS ERP
## STAGE 1, GATE 3: STORAGE & API SECURITY HARDENING REPORT

---

**Project:** InoManage Cloud Multi-Tenant SaaS ERP  
**Repository:** `D:\Project\Saas_inventory`  
**Branch:** `security/gate-3-storage-api`  
**Base Branch:** `security/gate-2-remediation`  
**Base Commit SHA:** `43b20fe1391ade412637de751cc5062ed243b27c`  
**Audit & Engineering Role:** Principal SaaS Security Architect & Laravel Multi-Tenant Security Specialist  
**Execution Date:** November 23, 2026  

---

## 1. EXECUTIVE SUMMARY

Stage 1, Gate 3 focused on eliminating critical storage path traversal vulnerabilities, unauthenticated file leakage, cross-tenant API exposure, unscoped Sanctum API tokens, and insecure webhook validation. 

Prior to Gate 3:
1. The storage fallback route in `routes/web.php` (`/storage/{path}`) permitted directory traversal sequences (`..`), allowing arbitrary files outside public storage to be served. It also served private tenant documents without authentication or tenant ownership verification.
2. Laravel Sanctum's `personal_access_tokens` table lacked a tenant identifier (`team_id`), creating a critical risk where an API token could be used across multiple tenants or spoof tenant contexts.
3. API routes (`routes/api.php`) lacked automatic tenant context binding and did not enforce token abilities against server-side tenant RBAC permissions.
4. Incoming SSLCommerz payment webhooks lacked amount consistency validation on background IPN calls, lacked signature validation in production mode, and ran without tenant-scoped execution context, risking `TenantContextMissingException` or unverified payment activation.

Under explicit authorization for Gate 3, all vulnerabilities have been completely remediated, hardened with architectural controls, verified with 11 automated security tests in `StorageAndApiSecurityTest.php`, and verified against the full 405-test regression suite with zero regressions.

---

## 2. STORAGE ARCHITECTURE & ACCESS-CONTROL HARDENING

### 2.1 Private Filesystem Disk (`config/filesystems.php`)
Configured a dedicated `private` disk rooted at `storage_path('app/private')` with `'visibility' => 'private'`. Tenant documents, contracts, attachments, and financial files are physically segregated from the web-accessible `app/public` directory.

### 2.2 Secure Storage Service (`App\Services\SecureStorageService`)
Implemented a centralized, security-first file manager with the following defenses:
- **Executable Extension Blacklist:** Strictly forbids uploads with extensions `.php`, `.phtml`, `.phar`, `.exe`, `.sh`, `.bin`, `.dll`, `.py`, `.js`, etc., preventing remote code execution (RCE).
- **Randomized File Hashing:** Generates cryptographically secure 40-character random filenames (`Str::random(40) . '.' . $extension`), eliminating file overwrite collisions and original-filename traversal.
- **Tenant Path Isolation:** All files are strictly partitioned by tenant ID (`tenants/{teamId}/{directory}/{filename}`).
- **Strict Traversal Elimination:** Rejects paths containing `..`, null bytes (`\0`), or backslashes (`\`). Verifies that canonical paths resolved via `realpath()` begin strictly with the tenant's root directory (`str_starts_with($realPath, $realTenantRoot)`).
- **Hardened HTTP Response Headers:** All downloads stream with security headers:
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: DENY`
  - `Content-Security-Policy: default-src 'none'`
  - `Cache-Control: private, no-cache, no-store, must-revalidate`

### 2.3 Public Storage Fallback Hardening (`routes/web.php`)
The route `/storage/{path}` was rewritten to:
1. Reject any traversal sequence (`..`, `\0`, `\`) with a fail-closed `404 Not Found`.
2. Intercept any access to `tenants/*` directories: requires active authentication and strictly verifies that `(int)$user->current_team_id === (int)$requestedTeamId` (unless Super Admin), completely preventing cross-tenant file scraping or unauthenticated document harvesting.
3. Validate `realpath()` against `storage_path('app/public')` to guarantee no file outside the public storage root is ever served.

### 2.4 Secure File Controller (`App\Http\Controllers\SecureFileController`)
- `GET /secure-files/download`: Requires web authentication and active tenant context. Validates that the requested file path belongs to the caller's active tenant and streams the file securely.
- `POST /secure-files/upload`: Validates MIME types, limits file size to 10MB, and securely stores files in the tenant's private repository.

---

## 3. SANCTUM API TOKEN & TENANT-SCOPING ARCHITECTURE

### 3.1 Tenant-Bound Tokens Schema
Created migration `database/migrations/2026_11_23_150000_add_team_id_to_personal_access_tokens_table.php`:
- Added `team_id` foreign key column referencing `teams(id)` with `cascadeOnDelete()`.
- Added composite index `(tokenable_type, tokenable_id, team_id)` for high-performance lookup.

### 3.2 Custom PersonalAccessToken Model
Created `App\Models\Sanctum\PersonalAccessToken` extending `Laravel\Sanctum\PersonalAccessToken`:
- Added `team_id` to `$fillable`.
- Added `team()` relationship to `App\Models\Team`.
- Registered globally in `AppServiceProvider::boot()` via `Sanctum::usePersonalAccessTokenModel(...)`.

### 3.3 Trait `HasTenantApiTokens` (`App\Traits\HasTenantApiTokens`)
Added to `App\Models\User`:
- `createTenantToken(int $teamId, string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)`:
  - Validates that the target tenant exists and is not suspended.
  - Verifies that the user genuinely holds membership in the target tenant (`$user->belongsToTeam($team)` or Super Admin).
  - Explicitly binds `team_id => $team->id` on token creation.
  - Rejects attempts to issue tokens for unauthorized tenants with `InvalidArgumentException`.

---

## 4. API AUTHENTICATION & MIDDLEWARE PIPELINE

### 4.1 Tenant Context Resolution on API Requests (`App\Services\TenantContextResolver`)
Updated `TenantContextResolver::resolve()`:
1. When an incoming API request contains a Bearer token, it resolves the token via `PersonalAccessToken::findToken($bearerToken)`.
2. If the token contains a bound `team_id`:
   - Retrieves the tenant via `Team::withoutGlobalScopes()->find($token->team_id)`.
   - Verifies the tenant is not suspended and the user is an active member.
   - Synchronizes Spatie's team permission context: `setPermissionsTeamId($tokenTeam->id)`.
   - Aligns `$user->current_team_id` to the token's tenant.
   - Returns the verified tenant to `TenantContext`.
3. If the token is bound to a deleted or suspended tenant, or if the user was removed from the team, it **fails closed** (returns `null`), denying all data access.

### 4.2 Middleware: `EnsureTenantApiContext` (`tenant.api`)
Registered as middleware alias `'tenant.api'`:
- Verifies that a valid user and active tenant context exist.
- Checks if the tenant is suspended, returning a structured `403 Forbidden` (`TenantSuspended`).
- Validates token-level team binding against active `TenantContext`.
- **Anti-Spoofing Guard:** Checks `X-Tenant-ID` header and `team_id` request parameter. If client attempts to pass a tenant ID that differs from the token's authorized tenant, the request is immediately rejected with `403 Forbidden` (`CrossTenantAccessBlocked`).

### 4.3 Middleware: `CheckTenantTokenAbility` (`token.ability`)
Enforces the **Principle of Least Privilege**:
- Verifies that the token possesses the required ability (e.g. `products:read`, `products:write`, `customers:read`, `orders:read`) or wildcard `*`. If missing, returns `403 Forbidden` (`TokenAbilityDenied`).
- Maps token abilities to server-side Spatie permissions (`manage-products`, `manage-customers`, `manage-orders`, `view-reports`).
- Verifies that the user actually possesses the corresponding permission in the active tenant. A token **cannot** grant permissions that the user's role does not hold. If the user lacks the permission, returns `403 Forbidden` (`PermissionDenied`).

### 4.4 Version 1 API Endpoints (`routes/api.php`)
- `GET /api/v1/auth/context`: Introspects active user, token abilities, and bound tenant.
- `GET /api/v1/products`: List products (scoped by `TenantScope`, requires `products:read`).
- `POST /api/v1/products`: Create product (requires `products:write`).
- `GET /api/v1/customers`: List customers (requires `customers:read`).
- `GET /api/v1/orders`: List sales orders (requires `orders:read`).
- `GET /api/v1/files/download`: Authorized private file download.

---

## 5. WEBHOOK SECURITY HARDENING

Audited and hardened `App\Http\Controllers\SslCommerzPaymentController`:
1. **Amount & Currency Validation:**
   - Both browser redirect (`success`) and background webhook (`ipn`) enforce currency `BDT`.
   - Strict floating-point verification: `abs((float) $payment->amount_bdt - $amount) <= 0.01`. Mismatched amounts are rejected immediately with `400 Bad Request` and logged as security warnings.
2. **Signature Verification in Production:**
   - In production mode (`services.sslcommerz.sandbox = false`), incoming IPN webhooks require `verify_sign` and `verify_key`.
   - Reconstructs parameter MD5 hash using the configured store secret (`store_passwd`). Missing or invalid signatures return `403 Forbidden`.
3. **Tenant-Contextual Execution:**
   - Incoming webhooks arrive without an authenticated user session.
   - Lookup for the pending payment uses `SubscriptionPayment::withoutGlobalScopes()->where('payment_number', $tranId)->first()`.
   - Payment activation and subscription upgrades are executed within the explicit tenant context of that payment:
     ```php
     $tenantContext->runWithTenant($team, function () use ($service, $payment) {
         $service->approvePayment($payment, null);
     });
     ```
   - Prevents `TenantContextMissingException` while strictly confining subscription mutations to the owning tenant.

---

## 6. VULNERABILITY REMEDIATION INVENTORY

| Vulnerability ID | Severity | Description | Status | Remediation Verification |
|---|---|---|---|---|
| **VULN-G3-01** | Critical | Storage path traversal (`../../`) via public fallback | **RESOLVED** | Verified via `test_storage_path_traversal_is_blocked` |
| **VULN-G3-02** | High | Unauthenticated access to private tenant letterheads and files | **RESOLVED** | Verified via `test_unauthenticated_user_cannot_access_tenant_storage_via_fallback` |
| **VULN-G3-03** | High | Dangerous file extension upload (RCE risk) | **RESOLVED** | Verified via `test_dangerous_file_extensions_are_rejected_on_upload` |
| **VULN-G3-04** | Critical | Unscoped Sanctum tokens allowing cross-tenant API access | **RESOLVED** | Verified via `test_sanctum_token_is_strictly_bound_to_tenant` |
| **VULN-G3-05** | High | Tenant ID spoofing via API headers/parameters | **RESOLVED** | Verified via `test_cross_tenant_token_spoofing_is_blocked` |
| **VULN-G3-06** | High | API token issuance for unauthorized tenants | **RESOLVED** | Verified via `test_token_cannot_be_issued_for_tenant_user_does_not_belong_to` |
| **VULN-G3-07** | High | Token ability bypass exceeding user role permissions | **RESOLVED** | Verified via `test_token_ability_and_server_side_permission_enforcement` |
| **VULN-G3-08** | Medium | Active API tokens for suspended tenants | **RESOLVED** | Verified via `test_suspended_tenant_token_is_rejected` |
| **VULN-G3-09** | High | Payment gateway webhook amount tampering | **RESOLVED** | Verified via `test_webhook_rejects_amount_mismatch` |
| **VULN-G3-10** | High | Webhook signature bypass in production mode | **RESOLVED** | Verified via `test_webhook_signature_verification_in_production` |

---

## 7. AUTOMATED SECURITY TEST RESULTS

### 7.1 Gate 3 Dedicated Security Suite (`StorageAndApiSecurityTest`)
Command: `php artisan test tests/Feature/Security/StorageAndApiSecurityTest.php`

```
PASS  Tests\Feature\Security\StorageAndApiSecurityTest
✓ tenant file storage isolates files by tenant                                  0.53s  
✓ storage path traversal is blocked                                             0.30s  
✓ unauthenticated user cannot access tenant storage via fallback                0.03s  
✓ dangerous file extensions are rejected on upload                              0.04s  
✓ sanctum token is strictly bound to tenant                                     0.05s  
✓ cross tenant token spoofing is blocked                                        0.03s  
✓ token cannot be issued for tenant user does not belong to                     0.03s  
✓ token ability and server side permission enforcement                          0.05s  
✓ suspended tenant token is rejected                                            0.03s  
✓ webhook rejects amount mismatch                                               0.04s  
✓ webhook signature verification in production                                  0.03s  

Tests:    11 passed (36 assertions)
Duration: 1.29s
```

### 7.2 Full Security Suite Summary (`tests/Feature/Security/`)
Command: `php artisan test tests/Feature/Security/`

- `FinancialReportAuthorizationTest`: 7 passed
- `RbacAuthorizationTest`: 9 passed
- `StorageAndApiSecurityTest`: 11 passed
- `SuperAdminImpersonationAuditTest`: 5 passed
- `TenantIsolationEngineTest`: 10 passed
- **Total Security Tests:** **42 passed (208 assertions), 0 failures**

### 7.3 Gate 1 & Gate 2 Regression Verification
Command: `php artisan test`

```
Tests:    7 skipped, 405 passed (2,525 assertions)
Duration: 59.81s
Result:   100% PASS (Zero Regressions)
```
*Note on skipped tests: 7 tests explicitly skipped correspond to Jetstream features disabled by design in this multi-tenant architecture (e.g. Jetstream Livewire API token GUI replaced by enterprise tenant-bound token engine, and self-registration toggle checks).*

---

## 8. CHANGED-FILE INVENTORY

| Action | Path | Description |
|---|---|---|
| **Created** | `database/migrations/2026_11_23_150000_add_team_id_to_personal_access_tokens_table.php` | Adds `team_id` and composite index to `personal_access_tokens` |
| **Created** | `app/Models/Sanctum/PersonalAccessToken.php` | Custom token model with `team_id` attribute and `team()` relation |
| **Created** | `app/Traits/HasTenantApiTokens.php` | Safe tenant token generation trait with membership validation |
| **Created** | `app/Services/SecureStorageService.php` | Private file manager with extension validation and path containment |
| **Created** | `app/Http/Controllers/SecureFileController.php` | Authorized private file download and upload controller |
| **Created** | `app/Http/Controllers/Api/V1/TenantApiController.php` | Tenant API controller for products, customers, orders, and files |
| **Created** | `app/Http/Middleware/EnsureTenantApiContext.php` | API middleware enforcing tenant resolution and anti-spoofing |
| **Created** | `app/Http/Middleware/CheckTenantTokenAbility.php` | API middleware verifying token ability and server-side Spatie roles |
| **Created** | `tests/Feature/Security/StorageAndApiSecurityTest.php` | 11 comprehensive security tests covering storage, API, tokens, webhooks |
| **Created** | `docs/security/GATE_3_STORAGE_API_SECURITY_REPORT.md` | Gate 3 formal security report deliverable |
| **Modified** | `config/filesystems.php` | Added `private` storage disk configuration |
| **Modified** | `config/services.php` | Added `sslcommerz` configuration block |
| **Modified** | `app/Models/User.php` | Added `HasTenantApiTokens` trait |
| **Modified** | `app/Policies/BaseTenantPolicy.php` | Added exception handling for undefined permissions in Spatie checks |
| **Modified** | `app/Providers/AppServiceProvider.php` | Registered custom `PersonalAccessToken` model with Sanctum |
| **Modified** | `app/Services/TenantContext.php` | Added `runWithTenant` alias for contextual execution |
| **Modified** | `app/Services/TenantContextResolver.php` | Hardened Bearer token resolution and token tenant binding |
| **Modified** | `app/Http/Controllers/SslCommerzPaymentController.php` | Hardened amount checks, signature verification, and tenant context |
| **Modified** | `bootstrap/app.php` | Registered `tenant.api` and `token.ability` middleware aliases |
| **Modified** | `routes/api.php` | Created Version 1 protected tenant API route group |
| **Modified** | `routes/web.php` | Hardened public storage fallback; added secure file download routes |

---

## 9. OUTSTANDING RISKS & LIMITATIONS

1. **Third-Party Symlink Fallback:** In environments where Windows symlinks are disabled or developer environments lack `php artisan storage:link`, the hardened `/storage/{path}` route safely serves assets with realpath containment. However, production deployments on Linux should always execute `php artisan storage:link` for maximum Nginx/Apache static file performance.
2. **IPN Network Whitelisting:** SSLCommerz IPN endpoints currently rely on cryptographic signature validation and transaction amount matching. In a production enterprise deployment, optional CIDR IP whitelisting for SSLCommerz gateway egress ranges can be added as an extra defense-in-depth layer.

---

## 10. GATE 3 ACCEPTANCE CHECKLIST

- [x] Branch `security/gate-3-storage-api` created from verified Gate 2.1 commit `43b20fe1391ade412637de751cc5062ed243b27c`.
- [x] Storage path traversal (`../../`) strictly rejected with realpath containment.
- [x] Unauthenticated access to tenant files via `/storage/tenants/*` is completely blocked.
- [x] Executable extensions (`.php`, `.exe`, `.sh`, etc.) are blocked on upload.
- [x] Private filesystem disk configured and operational.
- [x] Sanctum `personal_access_tokens` table includes `team_id` foreign key.
- [x] Custom `PersonalAccessToken` model registered with Sanctum.
- [x] Token creation verifies tenant membership and binds `team_id`.
- [x] API middleware rejects client-supplied tenant ID spoofing (`X-Tenant-ID` / `team_id`).
- [x] Token abilities and server-side tenant permissions are dual-enforced.
- [x] SSLCommerz IPN webhook enforces currency, amount matching, and production signature verification.
- [x] Webhook payment execution runs in isolated tenant context without scoping failures.
- [x] All 11 Gate 3 security tests pass.
- [x] All 42 security tests pass across the entire security suite.
- [x] All 405 application tests pass with zero regressions.
- [x] No CRM modules or Gate 4 activities have been initiated.
- [x] Working tree clean and ready for review.

---

## 11. NEXT RECOMMENDED STEP

Stage 1, Gate 3 is complete and verified. The security foundation is now ready for Stage 1, Gate 4 review: **Platform Audit Logging & Threat Monitoring**, or Stage 2 CRM foundation authorization upon your explicit instruction.
