# InoManage Cloud Multi-Tenant SaaS ERP
# CRM API & Endpoint Specifications

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Technical Interface Specification  
**Path:** `docs/crm/CRM_API_SPECIFICATION.md`

---

## 1. Architectural Overview & Authentication

The CRM module exposes two API surfaces:
1. **Inertia.js Web Endpoints:** Session-authenticated web routes returning Vue 3.5 components with inertia page props.
2. **Sanctum REST API Endpoints:** Headless JSON endpoints under `/api/v1/crm/...` for mobile integrations, webhooks, and third-party systems.

### Authentication & Token Abilities
* Web requests use standard Laravel session cookies with CSRF tokens.
* Headless REST API requests use Bearer tokens issued via Laravel Sanctum. Sanctum tokens must possess the requisite ability:
  * `crm:read` — Read-only access to leads, deals, contacts, cases.
  * `crm:write` — Create/update leads, deals, send omnichannel replies.
  * `crm:admin` — Manage pipelines, campaign approval, channel configuration.

---

## 2. Lead Management Endpoints

### 2.1 List Leads
* **Route:** `GET /crm/leads` (Inertia) / `GET /api/v1/crm/leads` (JSON)
* **Permission:** `crm.leads.view_assigned` or `crm.leads.view_all`
* **Response (JSON 200 OK):**
```json
{
  "data": [
    {
      "id": 104,
      "lead_number": "LED-2026-0104",
      "type": "b2b",
      "first_name": "Rafiqul",
      "last_name": "Islam",
      "company_name": "Dhaka Logistics Ltd",
      "email": "rafiq@dhakalogistics.com",
      "phone": "+8801711000000",
      "status": "qualified",
      "score": 85,
      "estimated_budget": "250000.00",
      "warehouse": {
        "id": 2,
        "name": "Tejgaon Distribution Hub"
      },
      "assigned_to": {
        "id": 14,
        "name": "Tanvir Ahmed"
      },
      "created_at": "2026-11-23T10:15:30Z"
    }
  ]
}
```

### 2.2 Convert Lead (Atomic Transaction)
* **Route:** `POST /crm/leads/{id}/convert`
* **Permission:** `crm.leads.convert`
* **Request Payload (JSON):**
```json
{
  "create_deal": true,
  "deal_title": "Bengal Textile - 50 Barcode Scanners",
  "pipeline_id": 1,
  "stage_id": 2,
  "deal_value": 500000.00,
  "expected_close_date": "2026-12-15"
}
```
* **Execution:** Executed inside a database transaction with a pessimistic lock on the lead, verifying customer non-duplication before insert.

---

## 3. Deals, Deal Items & Quotation Revisions

### 3.1 Manage Deal Line Items
* **Route:** `POST /crm/deals/{id}/items`
* **Permission:** `crm.deals.edit`
* **Request Payload (JSON):**
```json
{
  "product_id": 12,
  "item_name": "Zebra DS2208 Handheld Barcode Scanner",
  "quantity": 50.00,
  "unit_price": 10000.00,
  "notes": "Includes 3-year extended warranty"
}
```
* **Response (201 Created):** Returns created `crm_deal_items` record. Automatically recalculates `crm_deals.value`.

### 3.2 List Deal Quotation Revisions
* **Route:** `GET /crm/deals/{id}/quotations`
* **Permission:** `crm.deals.view_assigned` or `crm.deals.view_all`
* **Response (200 OK):** Lists all ERP quotation revisions linked to this deal via `quotations.deal_id`.

### 3.3 Transfer Deal to Another Branch
* **Route:** `POST /crm/deals/{id}/transfer`
* **Permission:** `crm.deals.transfer`
* **Request Payload (JSON):**
```json
{
  "target_warehouse_id": 3,
  "assigned_user_id": 18,
  "transfer_reason": "Client requested fulfillment from Chittagong Port Hub"
}
```
* **Validation:** Verifies target warehouse is active in tenant; verifies `assigned_user_id` has active `Membership` in tenant.

---

## 4. Omnichannel Inbox & Public Unsubscribe

### 4.1 Send Reply Message with Private Attachment
* **Route:** `POST /crm/inbox/conversations/{id}/messages` (multipart/form-data)
* **Permission:** `crm.conversations.reply`
* **Parameters:**
  * `body`: Message text string.
  * `attachment`: File (PDF, PNG, JPG, OGG).
* **Processing:** File uploaded to `storage/app/private/teams/{teamId}/crm/attachments/`, metadata (size, MIME, SHA-256 hash) stored in `crm_messages.attachments`.

### 4.2 Public Web Unsubscribe (Consent Withdrawal)
* **Route:** `POST /api/v1/crm/unsubscribe/{token}` (Public Unauthenticated)
* **Processing:** Resolves cryptographically signed token, matches `crm_consents` destination and `team_id`, marks status `'opted_out'`, and returns confirmation screen.

---

## 5. Webhook Ingress Endpoints (Platform Verified)

### 5.1 Meta WhatsApp Inbound Webhook
* **Verification Route:** `GET /api/v1/crm/webhooks/whatsapp`
  * Checks `hub.verify_token` against platform verification secret.
  * Returns `hub.challenge` on success.
* **Payload Ingress Route:** `POST /api/v1/crm/webhooks/whatsapp`
  * Validates `X-Hub-Signature-256` HMAC-SHA256 signature against **Platform Meta App Secret** (`config('services.meta.app_secret')`).
  * Computes payload hash and checks `crm_webhook_events` for replay deduplication. If duplicate: returns `200 OK` with `{"status": "duplicate_ignored"}`.
  * Extracts WABA ID and Phone Number ID to resolve `crm_channels.team_id`. If unknown: returns `200 OK` with `{"status": "unmatched_channel"}` without raising unhandled errors.
  * Dispatches `ProcessInboundWebhookJob` to Redis `crm-webhooks` queue.

---

## 6. Campaign Governance Endpoints

### 6.1 Submit for Approval
* **Route:** `POST /crm/campaigns/{id}/submit-approval`
* **Permission:** `crm.campaigns.create`
* **State Transition:** Moves from `draft` to `pending_approval`.

### 6.2 Authorize Campaign (Dual-Custody)
* **Route:** `POST /crm/campaigns/{id}/approve`
* **Permission:** `crm.campaigns.approve`
* **Pre-condition:** `auth()->id() !== campaign->created_by` (unconditional strict dual-custody: Owners are subject to the exact same rule; self-approval is rejected with HTTP 403).

### 6.3 Dispatch Campaign
* **Route:** `POST /crm/campaigns/{id}/dispatch`
* **Permission:** `crm.campaigns.dispatch`
* **Execution:** Chunks recipients into batches of 50; dispatches `ProcessCampaignBatchJob`.

---
*Next: Multi-channel telecommunications architecture is detailed in `docs/crm/CRM_COMMUNICATION_ARCHITECTURE.md`.*
