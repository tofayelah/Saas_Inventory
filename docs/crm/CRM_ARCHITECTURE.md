# InoManage Cloud Multi-Tenant SaaS ERP
# CRM Architecture & Component Boundaries

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Technical Architecture Specification  
**Path:** `docs/crm/CRM_ARCHITECTURE.md`

---

## 1. Modular Monolith Architecture

InoManage Cloud ERP utilizes a **Modular Monolith** architecture. The CRM subsystem is designed as an isolated internal module residing under `app/Modules/Crm` (with PSR-4 namespace `App\Modules\Crm`), avoiding architectural drift while retaining seamless transactional integration with the core ERP.

```
+-----------------------------------------------------------------------------------------+
|                                    Application Root                                     |
+-----------------------------------------------------------------------------------------+
|                                                                                         |
|   +---------------------------------------+   +-------------------------------------+   |
|   |         Core ERP Modules              |   |             CRM Module              |   |
|   |                                       |   |        (app/Modules/Crm)            |   |
|   |  - Inventory & Warehouses             |   |                                     |   |
|   |  - Point of Sale (POS)                |   |  - Leads & Scoring                  |   |
|   |  - Sales Orders & Invoicing           |   |  - Deals, Pipelines & Deal Items    |   |
|   |  - Customers (Master Ledger)          |   |  - Omnichannel Inbox (WA/SMS/Mail)  |   |
|   |  - Double-Entry Accounting            |   |  - Marketing Campaigns & Segments   |   |
|   |  - Workshop Service Tickets           |   |  - Customer Support Cases           |   |
|   +-------------------+-------------------+   +------------------+------------------+   |
|                       ^                                          |                      |
|                       |            Domain Event / Adapter        |                      |
|                       +------------------------------------------+                      |
|                                                                                         |
+-----------------------------------------------------------------------------------------+
|                               Shared Platform Foundation                                |
|  - Tenancy Isolation (App\Traits\BelongsToTenant, App\Contracts\TenantAware)            |
|  - Security & RBAC (Spatie Permission, Gate Policies, Multi-Branch Membership)          |
|  - Centralized Tenant Validation (TenantBoundaryValidator)                              |
|  - Asynchronous Queues & Horizon (Redis, TenantContext Restoration, State Cleanup)      |
|  - Encrypted Credentials (AES-256-GCM via Crypt::encryptString)                         |
|  - Dynamic Isolated SMTP Mailer (Zero Global Config Mutation)                           |
|  - Durable Webhook Event Staging (crm_webhook_events) & Replay Defense                  |
|  - Private Storage Disk & Tenant Download Authorization                                 |
+-----------------------------------------------------------------------------------------+
```

---

## 2. Directory Structure and Namespaces

```text
app/Modules/Crm/
├── Contracts/                       # Service interfaces and extension points
│   ├── OmnichannelProviderInterface.php
│   ├── SmsGatewayInterface.php
│   ├── CampaignDispatchInterface.php
│   └── TenantMailTransportInterface.php
├── Controllers/                     # Inertia.js Web and Sanctum API Controllers
│   ├── LeadController.php
│   ├── DealController.php
│   ├── DealItemController.php
│   ├── PipelineController.php
│   ├── ContactController.php
│   ├── OmnichannelInboxController.php
│   ├── CampaignController.php
│   ├── SupportCaseController.php
│   ├── ActivityController.php
│   ├── AutomationRuleController.php
│   ├── ChannelSettingsController.php
│   └── WebhookIngressController.php
├── DTOs/                            # Type-safe Data Transfer Objects
│   ├── LeadCreateData.php
│   ├── DealPipelineData.php
│   ├── InboundMessageData.php
│   ├── WebhookEventData.php
│   └── CampaignDispatchData.php
├── Events/                          # Domain Events
│   ├── LeadCreated.php
│   ├── LeadConverted.php
│   ├── DealStageChanged.php
│   ├── DealWon.php
│   ├── InboundMessageReceived.php
│   ├── CampaignApproved.php
│   ├── SupportCaseEscalatedToRepair.php
│   └── BranchAssignmentChanged.php
├── Jobs/                            # Tenant-aware Asynchronous Workers
│   ├── ProcessInboundWebhookJob.php
│   ├── DispatchCampaignMessageJob.php
│   ├── ProcessCampaignBatchJob.php
│   ├── SendOmnichannelReplyJob.php
│   └── EvaluateAutomationRulesJob.php
├── Listeners/                       # Event Handlers & ERP Adapters
│   ├── SyncWonDealToQuotation.php
│   ├── NotifyAssignedAgent.php
│   ├── LogCrmActivity.php
│   ├── SpawnWorkshopRepairTicket.php
│   └── AuditBranchTransfer.php
├── Models/                          # Eloquent Models (BelongsToTenant)
│   ├── CrmLead.php
│   ├── CrmContact.php
│   ├── CrmPipeline.php
│   ├── CrmPipelineStage.php
│   ├── CrmDeal.php
│   ├── CrmDealItem.php              # Line items attached to commercial deals
│   ├── CrmChannel.php
│   ├── CrmConversation.php
│   ├── CrmMessage.php
│   ├── CrmWebhookEvent.php          # Raw durable webhook audit & replay protection
│   ├── CrmCampaign.php
│   ├── CrmCampaignRecipient.php
│   ├── CrmSegment.php
│   ├── CrmConsent.php
│   ├── CrmSupportCase.php
│   ├── CrmActivity.php
│   ├── CrmAutomationRule.php
│   └── CrmAutomationLog.php
├── Policies/                        # Authorization Policies
│   ├── CrmLeadPolicy.php
│   ├── CrmDealPolicy.php
│   ├── CrmCampaignPolicy.php
│   └── CrmSupportCasePolicy.php
├── Requests/                        # Form Requests with Strict Validation
│   ├── StoreLeadRequest.php
│   ├── UpdateDealRequest.php
│   ├── StoreDealItemRequest.php
│   ├── SendMessageRequest.php
│   ├── StoreCampaignRequest.php
│   └── StoreAutomationRuleRequest.php
├── Services/                        # Business Logic & Orchestration
│   ├── LeadService.php
│   ├── DealService.php
│   ├── OmnichannelService.php
│   ├── CampaignService.php
│   ├── SupportCaseService.php
│   ├── TenantBoundaryValidator.php  # Centralized cross-tenant reference validator
│   ├── WebhookDeduplicationService.php
│   ├── TenantMailService.php        # Dynamic isolated SMTP with explicit shutdown
│   ├── Providers/
│   │   ├── WhatsAppCloudApiService.php
│   │   ├── BulkSmsBdService.php
│   │   └── TenantSmtpMailService.php
│   └── ErpAdapterService.php
└── CrmServiceProvider.php           # Module service provider & route registration
```

---

## 3. Component Boundaries & Responsibilities

### 3.1 Lead Management Domain
* **Boundary:** Manages unvalidated prospects.
* **Responsibilities:** Multi-source ingestion, duplicate checks based on normalized E.164 phone numbers and emails, automated scoring, and branch assignment.
* **Conversion Flow:** Converts a qualified `CrmLead` into an ERP `Customer` entity (if non-existent) and an active `CrmDeal` within an atomic database transaction.

### 3.2 Contact & Account Domain
* **Boundary:** Counterparty management.
* **Responsibilities:** `customers` table represents the canonical commercial account. `CrmContact` represents individual persons (e.g. Finance Director, Buyer, Site Engineer) belonging to that Customer. The `clients` table in Project Costing remains unaffected.

### 3.3 Deal, Pipeline & Quotation Revision Domain
* **Boundary:** Commercial opportunities, line items, and forecasting.
* **Responsibilities:** Multi-pipeline support, customizable stages, closing probabilities, and deal line items (`CrmDealItem`) tracking proposed products, quantities, and pricing.
* **Quotation Revisions:** Quotations link back to deals via `quotations.deal_id`. A deal can have multiple historical quotation revisions (`hasMany(Quotation::class)`), with `crm_deals.quotation_id` pointing to the currently accepted/active quotation.

### 3.4 Omnichannel Inbox & Webhook Domain
* **Boundary:** Real-time messaging and event ingress.
* **Responsibilities:** Ingests webhooks from Meta Cloud API, BulkSMSBD, and SMTP/Email. Verifies Platform App Secret on inbound webhooks, stages raw payloads in `crm_webhook_events` for replay defense and idempotency, and routes messages to `crm_conversations`.

### 3.5 Marketing Campaign & Governance Domain
* **Boundary:** Outbound bulk marketing.
* **Responsibilities:** Target audience query building (`CrmSegment`), message templating, BTRC regulatory DND and opt-out suppression (`CrmConsent`), mandatory dual-custody approval lifecycle, and rate-limited chunked dispatching.

### 3.6 Customer Support Case Domain
* **Boundary:** Post-sale customer inquiries, disputes, and SLAs.
* **Responsibilities:** Ticket intake, priority assessment, SLA tracking, agent reassignment, and conversation threading.
* **Demarcation from Workshop Tickets:** If an inquiry involves physical equipment servicing, the support agent triggers an escalation event, creating an ERP `ServiceTicket` linked back to the `CrmSupportCase`.

---

## 4. Architectural Invariants and Tenancy Protocols

1. **Canonical Tenancy Trait:** Every CRM Eloquent model uses the existing canonical trait:
   ```php
   use App\Traits\BelongsToTenant;
   use App\Contracts\TenantAware;
   ```
   and implements `\App\Contracts\TenantAware`. Any query attempted without an active tenant context throws `TenantContextMissingException`.
2. **Database Unique Keys:** All unique identifiers (e.g., `deal_number`, `case_number`, `campaign_code`) are composite unique with `team_id`:
   ```sql
   UNIQUE KEY `crm_deals_team_deal_number_unique` (`team_id`, `deal_number`)
   ```
3. **Queue Tenancy & State Cleanup:** Background workers restore tenant context inside `handle()` and invoke `$tenantContext->clear()` and `$mailService->resetTransports()` in `finally` blocks across both success and failure paths to guarantee zero cross-tenant memory leakage.
4. **Decoupled ERP Integration:** Core ERP models (`Customer`, `Quotation`, `SalesOrder`, `Warehouse`, `ServiceTicket`) **must not** reference CRM models. Communication flows via domain events and `ErpAdapterService`.

---
*Next: Detailed database design and entity relationship diagrams are specified in `docs/crm/CRM_DATABASE_DESIGN.md`.*
