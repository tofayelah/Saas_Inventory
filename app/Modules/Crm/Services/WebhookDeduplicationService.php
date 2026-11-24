<?php

namespace App\Modules\Crm\Services;

use App\Exceptions\TenantIsolationException;
use App\Modules\Crm\Models\CrmMessage;
use App\Modules\Crm\Models\CrmWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class WebhookDeduplicationService
{
    /**
     * Stage an incoming webhook event with replay defense and concurrency-safe deduplication.
     * Uses composite unique index on (provider, event_scope_key, external_event_id).
     */
    public function stageEvent(
        string $provider,
        string $eventScopeKey,
        string $externalEventId,
        string $payload,
        ?array $headers = [],
        ?int $teamId = null
    ): CrmWebhookEvent {
        $payloadHash = hash('sha256', $payload);

        try {
            return CrmWebhookEvent::create([
                'team_id' => $teamId,
                'provider' => $provider,
                'event_scope_key' => $eventScopeKey,
                'external_event_id' => $externalEventId,
                'payload_hash' => $payloadHash,
                'headers' => $headers,
                'payload' => $payload,
                'status' => 'pending',
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Check for unique key constraint violation (SQLSTATE 23000 / MySQL 1062 / SQLite 19)
            if ($this->isUniqueConstraintViolation($e)) {
                Log::info("Duplicate webhook event delivery detected for [{$provider}:{$eventScopeKey}:{$externalEventId}]. Returning existing record.");

                /** @var CrmWebhookEvent $existing */
                $existing = CrmWebhookEvent::where('provider', $provider)
                    ->where('event_scope_key', $eventScopeKey)
                    ->where('external_event_id', $externalEventId)
                    ->first();

                if ($existing) {
                    // Return existing event marked as duplicate without overwriting processed state
                    $duplicateRecord = clone $existing;
                    $duplicateRecord->status = 'duplicate';
                    return $duplicateRecord;
                }
            }

            throw $e;
        }
    }

    /**
     * Idempotently handle message delivery receipts and status callbacks.
     */
    public function handleMessageStatusCallback(
        int $teamId,
        string $externalMessageId,
        string $newStatus,
        ?string $timestamp = null
    ): CrmMessage {
        /** @var CrmMessage $message */
        $message = CrmMessage::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('external_message_id', $externalMessageId)
            ->first();

        if (!$message) {
            throw new TenantIsolationException("Message [External ID: {$externalMessageId}] does not belong to active tenant [ID: {$teamId}].");
        }

        $eventTime = $timestamp ? \Illuminate\Support\Carbon::parse($timestamp) : now();

        $updates = [];
        if ($newStatus === 'delivered' && empty($message->delivered_at)) {
            $updates['delivered_at'] = $eventTime;
            $updates['status'] = 'delivered';
        } elseif ($newStatus === 'read' && empty($message->read_at)) {
            $updates['read_at'] = $eventTime;
            $updates['status'] = 'read';
            if (empty($message->delivered_at)) {
                $updates['delivered_at'] = $eventTime;
            }
        } elseif ($newStatus === 'failed') {
            $updates['status'] = 'failed';
        } elseif (!in_array($message->status, ['delivered', 'read']) && $newStatus === 'sent') {
            $updates['status'] = 'sent';
            $updates['sent_at'] = $eventTime;
        }

        if (!empty($updates)) {
            $message->update($updates);
        }

        return $message;
    }

    /**
     * Determine if a database exception represents a unique constraint violation.
     */
    protected function isUniqueConstraintViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        return $code === '23000' ||
            str_contains($message, 'unique constraint') ||
            str_contains($message, 'duplicate entry') ||
            str_contains($message, 'uq_crm_webhook_events');
    }
}
