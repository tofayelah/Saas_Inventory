<?php

namespace App\Services;

use App\Models\SubscriptionPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SslCommerzValidationService
{
    /**
     * Authoritatively validate a payment transaction directly with SSLCommerz's Order Validation API.
     *
     * @param  \App\Models\SubscriptionPayment  $payment
     * @param  string  $valId
     * @return array{is_valid: bool, reason: string|null, payload: array|null}
     */
    public function validateTransaction(SubscriptionPayment $payment, string $valId): array
    {
        if (empty($valId)) {
            return [
                'is_valid' => false,
                'reason' => 'Missing provider validation identifier (val_id).',
                'payload' => null,
            ];
        }

        // 1. Check for duplicate val_id across existing approved payments (Replay/Duplicate Guard)
        $alreadyUsed = SubscriptionPayment::withoutGlobalScopes()
            ->where('trx_id', $valId)
            ->where('id', '!=', $payment->id)
            ->where('status', 'approved')
            ->exists();

        if ($alreadyUsed) {
            Log::warning("SSLCommerz duplicate transaction ID detected. val_id [{$valId}] already used by another payment.", [
                'payment_number' => $payment->payment_number,
                'val_id' => $valId,
            ]);

            return [
                'is_valid' => false,
                'reason' => 'Transaction validation ID has already been utilized by another approved payment.',
                'payload' => null,
            ];
        }

        // 2. Resolve Environment Endpoint & Credentials
        $isSandbox = config('services.sslcommerz.sandbox', true);
        $storeId = config('services.sslcommerz.store_id', 'saas_sandbox');
        $storePasswd = config('services.sslcommerz.store_password', 'sandbox_pass');

        $baseUrl = $isSandbox
            ? 'https://sandbox.sslcommerz.com'
            : 'https://securepay.sslcommerz.com';

        $validationEndpoint = "{$baseUrl}/validator/api/validationserverAPI.php";

        // 3. Execute Outbound Server-to-Server Query with Safe Timeout
        try {
            $response = Http::timeout(10)->get($validationEndpoint, [
                'val_id' => $valId,
                'store_id' => $storeId,
                'store_passwd' => $storePasswd,
                'format' => 'json',
            ]);
        } catch (\Throwable $e) {
            Log::error("SSLCommerz Order Validation API connection failure for payment [{$payment->payment_number}]: {$e->getMessage()}", [
                'payment_number' => $payment->payment_number,
                'val_id' => $valId,
            ]);

            return [
                'is_valid' => false,
                'reason' => 'Payment gateway validation service is currently unreachable. Please retry.',
                'payload' => null,
            ];
        }

        if (!$response->successful()) {
            Log::warning("SSLCommerz Order Validation API returned non-200 HTTP status [{$response->status()}] for payment [{$payment->payment_number}].");

            return [
                'is_valid' => false,
                'reason' => "Provider validation returned HTTP status: {$response->status()}",
                'payload' => null,
            ];
        }

        $data = $response->json();

        if (!is_array($data)) {
            Log::warning("SSLCommerz Order Validation API returned invalid or empty JSON for payment [{$payment->payment_number}].");

            return [
                'is_valid' => false,
                'reason' => 'Invalid response structure from payment validation service.',
                'payload' => null,
            ];
        }

        // 4. Validate Provider Transaction Status (Fail Closed - Sandbox also requires VALID/VALIDATED)
        $remoteStatus = strtoupper((string) ($data['status'] ?? ''));
        if (!in_array($remoteStatus, ['VALID', 'VALIDATED'], true)) {
            Log::warning("SSLCommerz authoritative status not valid: [{$remoteStatus}] for payment [{$payment->payment_number}].", [
                'payment_number' => $payment->payment_number,
                'remote_status' => $remoteStatus,
            ]);

            return [
                'is_valid' => false,
                'reason' => "Transaction status '{$remoteStatus}' is not valid for approval.",
                'payload' => $data,
            ];
        }

        // 5. Verify Transaction Identifier Matches Stored Payment Record
        $remoteTranId = (string) ($data['tran_id'] ?? '');
        if ($remoteTranId !== $payment->payment_number) {
            Log::warning("SSLCommerz transaction ID mismatch: Expected [{$payment->payment_number}], Provider reported [{$remoteTranId}].");

            return [
                'is_valid' => false,
                'reason' => 'Transaction ID mismatch between gateway and application record.',
                'payload' => $data,
            ];
        }

        // 6. Verify Exact Transaction Amount
        $remoteAmount = (float) ($data['amount'] ?? 0);
        if (abs((float) $payment->amount_bdt - $remoteAmount) > 0.01) {
            Log::warning("SSLCommerz authoritative amount mismatch for payment [{$payment->payment_number}]: Expected [{$payment->amount_bdt}], Provider confirmed [{$remoteAmount}].");

            return [
                'is_valid' => false,
                'reason' => 'Transaction amount mismatch between gateway and application record.',
                'payload' => $data,
            ];
        }

        // 7. Verify Currency is BDT
        $remoteCurrency = strtoupper((string) ($data['currency'] ?? ''));
        if ($remoteCurrency !== 'BDT') {
            Log::warning("SSLCommerz authoritative currency mismatch: Expected BDT, Provider reported [{$remoteCurrency}].");

            return [
                'is_valid' => false,
                'reason' => "Unsupported transaction currency [{$remoteCurrency}].",
                'payload' => $data,
            ];
        }

        Log::info("SSLCommerz authoritative server-to-server validation successful for payment [{$payment->payment_number}].", [
            'payment_number' => $payment->payment_number,
            'val_id' => $valId,
            'amount' => $remoteAmount,
        ]);

        return [
            'is_valid' => true,
            'reason' => null,
            'payload' => $data,
        ];
    }
}
