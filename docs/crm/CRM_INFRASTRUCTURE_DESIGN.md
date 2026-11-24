# InoManage Cloud Multi-Tenant SaaS ERP
# CRM Infrastructure, Queues & Worker Architecture

**Document Version:** 2.1.0  
**Phase:** Stage 2 CRM, Gate 0: Architecture & Design Revision 2.1 (Final Security Reconciliation)  
**Classification:** Infrastructure & DevOps Architecture  
**Path:** `docs/crm/CRM_INFRASTRUCTURE_DESIGN.md`

---

## 1. Asynchronous Queue Architecture & Segregation

The CRM module relies extensively on asynchronous background processing to decouple user HTTP requests from high-latency external telecommunications networks.

To prevent bulk marketing broadcasts from delaying high-priority customer chat replies or incoming webhook ingestion, **queues are segregated into dedicated channels**:

```
+-----------------------------------------------------------------------------------------+
|                                    Redis Queue Engine                                   |
+-----------------------------------------------------------------------------------------+
|                                                                                         |
|   +-----------------------+   +-----------------------+   +-------------------------+   |
|   |     crm-webhooks      |   |     crm-messages      |   |      crm-campaigns      |   |
|   |  (Priority: Highest)  |   |    (Priority: High)   |   |   (Priority: Standard)  |   |
|   |                       |   |                       |   |                         |   |
|   |  - Incoming WhatsApp  |   |  - Agent chat replies |   |  - Bulk promotional SMS |   |
|   |  - Delivery receipts  |   |  - Quotation alerts   |   |  - Segment broadcasts   |   |
|   |  - Opt-out traps      |   |  - Real-time alerts   |   |  - Template blasts      |   |
|   +-----------------------+   +-----------------------+   +-------------------------+   |
|                                                                                         |
+-----------------------------------------------------------------------------------------+
```

### 1.1 Queue Definitions & SLA Targets
1. **`crm-webhooks` (Queue 1):**
   * **Purpose:** Processes raw incoming payloads staged in `crm_webhook_events`.
   * **Target Latency:** < 500 ms processing time.
   * **Retry Policy:** 3 attempts with exponential backoff (`backoff: [2, 10, 30]`).
2. **`crm-messages` (Queue 2):**
   * **Purpose:** Handles interactive agent messages sent from the Unified Inbox.
   * **Target Latency:** < 2,000 ms outbound delivery.
   * **Retry Policy:** 2 attempts.
3. **`crm-campaigns` (Queue 3):**
   * **Purpose:** Handles large marketing dispatches chunked into batches of 50 recipients.
   * **Target Latency:** Throttled to telecommunication caps.
   * **Retry Policy:** 1 retry per recipient failure.
4. **`crm-automations` (Queue 4):**
   * **Purpose:** Evaluates allowlisted automation rules and actions.
   * **Target Latency:** < 5,000 ms.

---

## 2. Configurable Telecommunications Rate Limiting

To accommodate provider tier variations and account limits, rate limits are implemented via Redis token-bucket limiters using configurable application settings:

```php
$waLimit = config('services.meta.wa_rate_limit', 80);

Redis::throttle('meta-wa:' . $phoneNumberId)
    ->allow($waLimit)
    ->every(1)
    ->then(function () use ($job) {
        $job->execute();
    }, function () use ($job) {
        $job->release(2);
    });
```

* **WhatsApp Cloud API:** Default 80 messages/second per phone number (`config('services.meta.wa_rate_limit')`).
* **BulkSMSBD Gateway:** Default 20 requests/second per tenant API key (`config('services.bulksmsbd.rate_limit')`).
* **SMTP Outbound:** Default 10 emails/second per host (`config('services.smtp.rate_limit')`).

---

## 3. Dedicated Per-Tenant Symfony Mailer Transport with Explicit Shutdown and Cleanup

In long-running queue workers (`php artisan queue:work`), PHP processes persist across multiple job executions. Memory leakage or stale tenant state poses a severe cross-tenant security risk.

### 3.1 Job Tenancy Invariant & State Cleanup Across All Paths
Every CRM background job implements the Stage 1 tenancy restoration protocol and explicit transport cleanup via dedicated per-tenant Symfony Mailer transport with explicit shutdown and cleanup:
```php
class ProcessCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $teamId;
    public int $campaignId;
    public array $recipientIds;

    public function __construct(int $teamId, int $campaignId, array $recipientIds)
    {
        $this->teamId = $teamId;
        $this->campaignId = $campaignId;
        $this->recipientIds = $recipientIds;
    }

    public function handle(TenantContext $tenantContext, TenantMailService $mailService): void
    {
        // 1. Establish strict tenant context
        $tenantContext->setTenant($this->teamId);

        try {
            // 2. Execute tenant-scoped business logic
            $this->processBatch();
        } finally {
            // 3. Clear tenant context and mailer state to prevent memory pollution
            $mailService->resetTransports(); // Explicitly calls $transport->stop() and unsets mailer
            $tenantContext->clear();
        }
    }
}
```

---

## 4. Durable Webhook Staging, Deduplication & Retention

* **Staging Table (`crm_webhook_events`):** Raw inbound webhooks are written immediately upon ingress before parsing.
* **Provider Event Identity:** `UNIQUE (provider, external_event_id)` ensures concurrency-safe duplicate prevention.
* **Header Redaction:** Sensitive headers (Authorization, Bearer tokens, cookies) are redacted prior to persisting into the `headers` JSON column.
* **Pruning Schedule:** A daily scheduled command (`crm:purge-webhook-events`) deletes processed webhook events older than 30 days.

---
*Next: Quality assurance, security testing matrices, and automated test plans are documented in `docs/crm/CRM_TEST_STRATEGY.md`.*
