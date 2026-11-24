<?php

namespace App\Modules\Crm\Services;

use App\Exceptions\TenantIsolationException;
use App\Modules\Crm\Models\CrmChannel;
use App\Services\TenantContext;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

class TenantMailService
{
    protected ?TransportInterface $activeTransport = null;
    protected ?Mailer $activeMailer = null;

    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Send email using a dedicated per-tenant Symfony Mailer transport with explicit shutdown and cleanup.
     * Guaranteed cleanup across both success and exception paths in a finally block.
     */
    public function sendTenantMail(
        int $teamId,
        Email $email,
        ?TransportInterface $customTransport = null
    ): bool {
        // 1. Establish tenant context
        $this->tenantContext->setTenant($teamId);

        try {
            if ($customTransport) {
                $this->activeTransport = $customTransport;
            } else {
                /** @var CrmChannel $channel */
                $channel = CrmChannel::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->where('channel_type', 'email')
                    ->where('is_active', true)
                    ->first();

                if (!$channel) {
                    throw new TenantIsolationException("Tenant [ID: {$teamId}] does not have an active email channel.");
                }

                $creds = $channel->credentials;
                $this->activeTransport = new EsmtpTransport(
                    $creds['smtp_host'] ?? '127.0.0.1',
                    (int) ($creds['smtp_port'] ?? 587),
                    ($creds['smtp_encryption'] ?? '') === 'tls'
                );

                if (!empty($creds['smtp_username'])) {
                    $this->activeTransport->setUsername($creds['smtp_username']);
                }
                if (!empty($creds['smtp_password'])) {
                    $this->activeTransport->setPassword($creds['smtp_password']);
                }
            }

            $this->activeMailer = new Mailer($this->activeTransport);
            $this->activeMailer->send($email);

            return true;
        } finally {
            // Explicit shutdown and cleanup of dedicated per-tenant Symfony Mailer transport
            $this->resetTransports();
            $this->tenantContext->clear();
        }
    }

    /**
     * Explicitly close transport socket, disconnect, and purge state from memory.
     */
    public function resetTransports(): void
    {
        if ($this->activeTransport instanceof EsmtpTransport) {
            try {
                $this->activeTransport->stop();
            } catch (\Throwable $e) {
                // Ignore socket shutdown errors during teardown
            }
        }

        $this->activeTransport = null;
        $this->activeMailer = null;
    }

    public function getActiveTransport(): ?TransportInterface
    {
        return $this->activeTransport;
    }
}
