<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Models\Team;
use App\Services\SubscriptionService;
use App\Services\SslCommerzValidationService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SslCommerzPaymentController extends Controller
{
    /**
     * Handle successful payment return from SSLCommerz.
     */
    public function success(
        Request $request,
        SubscriptionService $service,
        TenantContext $tenantContext,
        SslCommerzValidationService $validator
    ): RedirectResponse {
        $tranId = (string) $request->input('tran_id', '');
        $valId = (string) $request->input('val_id', '');
        $amount = (float) $request->input('amount', 0);
        $currency = strtoupper((string) $request->input('currency', 'BDT'));
        $status = strtoupper((string) $request->input('status', ''));

        if (!$tranId) {
            return redirect()->route('subscription.index')->withErrors(['error' => 'Invalid gateway response: Transaction ID missing.']);
        }

        $payment = SubscriptionPayment::withoutGlobalScopes()->where('payment_number', $tranId)->first();

        if (!$payment) {
            return redirect()->route('subscription.index')->withErrors(['error' => 'Subscription payment record not found.']);
        }

        if ($payment->status === 'approved') {
            return redirect()->route('subscription.index')->with('success', 'Payment has already been processed and verified.');
        }

        // 1. Strict Currency & Amount Verification on Incoming Payload
        if ($currency !== 'BDT') {
            Log::warning("SSLCommerz currency mismatch for {$tranId}. Expected BDT, received: {$currency}");
            return redirect()->route('subscription.index')->withErrors(['error' => 'Unsupported currency in payment response.']);
        }

        if ($amount > 0 && abs((float) $payment->amount_bdt - $amount) > 0.01) {
            Log::warning("SSLCommerz amount mismatch for {$tranId}. Expected: {$payment->amount_bdt}, Received: {$amount}");
            return redirect()->route('subscription.index')->withErrors(['error' => 'Payment amount mismatch. Please contact support.']);
        }

        // 2. Validate Gateway Parameter Signature
        if (!$this->verifyGatewaySignature($request)) {
            Log::warning("SSLCommerz signature verification failed for {$tranId}.");
            return redirect()->route('subscription.index')->withErrors(['error' => 'Payment signature verification failed.']);
        }

        // 3. Strict Status Verification (No Sandbox Bypass - SEC-G3-REV-02)
        if (!in_array($status, ['VALID', 'VALIDATED'], true)) {
            $payment->update([
                'status' => 'rejected',
                'rejection_reason' => "Gateway status: {$status}",
                'gateway_payload' => $request->all(),
            ]);

            return redirect()->route('subscription.index')->withErrors(['error' => "Payment verification failed with status: {$status}"]);
        }

        if (!$valId) {
            return redirect()->route('subscription.index')->withErrors(['error' => 'Missing transaction validation identifier from gateway.']);
        }

        // 4. Authoritative Server-to-Server Order Validation API Call (SEC-G3-REV-01)
        $validation = $validator->validateTransaction($payment, $valId);
        if (!$validation['is_valid']) {
            DB::transaction(function () use ($payment, $validation, $request) {
                $locked = SubscriptionPayment::withoutGlobalScopes()->where('id', $payment->id)->lockForUpdate()->first();
                if ($locked && $locked->status === 'pending') {
                    $locked->update([
                        'status' => 'rejected',
                        'rejection_reason' => $validation['reason'],
                        'gateway_payload' => $validation['payload'] ?? $request->all(),
                    ]);
                }
            });

            return redirect()->route('subscription.index')->withErrors(['error' => "Payment validation failed: {$validation['reason']}"]);
        }

        // 5. Concurrency-Safe Payment Approval within isolated Tenant Context (SEC-G4-PRI-01)
        $team = Team::withoutGlobalScopes()->find($payment->team_id);
        if ($team) {
            $tenantContext->runWithTenant($team, function () use ($service, $payment, $valId, $validation, $request) {
                DB::transaction(function () use ($service, $payment, $valId, $validation, $request) {
                    $lockedPayment = SubscriptionPayment::withoutGlobalScopes()
                        ->where('id', $payment->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedPayment) {
                        return;
                    }

                    // Idempotent: already approved by concurrent IPN or redirect
                    if ($lockedPayment->status === 'approved') {
                        return;
                    }

                    $lockedPayment->update([
                        'trx_id' => $valId,
                        'gateway_payload' => $validation['payload'] ?? $request->all(),
                    ]);

                    $service->approvePayment($lockedPayment, null);
                });
            });
        }

        return redirect()->route('subscription.index')->with('success', 'Payment processed and verified successfully via SSLCommerz. Subscription upgraded.');
    }

    /**
     * Handle failed payment return from SSLCommerz.
     */
    public function fail(Request $request): RedirectResponse
    {
        $tranId = (string) $request->input('tran_id', '');

        if ($tranId) {
            DB::transaction(function () use ($tranId, $request) {
                $payment = SubscriptionPayment::withoutGlobalScopes()
                    ->where('payment_number', $tranId)
                    ->lockForUpdate()
                    ->first();

                if ($payment && $payment->status === 'pending') {
                    $payment->update([
                        'status' => 'rejected',
                        'rejection_reason' => 'Transaction failed at SSLCommerz gateway.',
                        'gateway_payload' => $request->all(),
                    ]);
                }
            });
        }

        return redirect()->route('subscription.index')->withErrors([
            'error' => 'SSLCommerz payment failed. Please try again or submit your TrxID manually.',
        ]);
    }

    /**
     * Handle cancelled payment return from SSLCommerz.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $tranId = (string) $request->input('tran_id', '');

        if ($tranId) {
            DB::transaction(function () use ($tranId, $request) {
                $payment = SubscriptionPayment::withoutGlobalScopes()
                    ->where('payment_number', $tranId)
                    ->lockForUpdate()
                    ->first();

                if ($payment && $payment->status === 'pending') {
                    $payment->update([
                        'status' => 'rejected',
                        'rejection_reason' => 'Customer cancelled checkout on SSLCommerz gateway.',
                        'gateway_payload' => $request->all(),
                    ]);
                }
            });
        }

        return redirect()->route('subscription.index')->with('warning', 'SSLCommerz payment checkout was cancelled.');
    }

    /**
     * Handle background IPN webhook from SSLCommerz with strict signature, amount, and server-to-server validation.
     */
    public function ipn(
        Request $request,
        SubscriptionService $service,
        TenantContext $tenantContext,
        SslCommerzValidationService $validator
    ): JsonResponse {
        $tranId = (string) $request->input('tran_id', '');
        $valId = (string) $request->input('val_id', '');
        $amount = (float) $request->input('amount', 0);
        $currency = strtoupper((string) $request->input('currency', 'BDT'));
        $status = strtoupper((string) $request->input('status', ''));

        if (!$tranId) {
            return response()->json(['status' => 'error', 'message' => 'Missing tran_id'], 400);
        }

        $payment = SubscriptionPayment::withoutGlobalScopes()->where('payment_number', $tranId)->first();

        if (!$payment) {
            return response()->json(['status' => 'error', 'message' => 'Payment not found'], 404);
        }

        if ($payment->status === 'approved') {
            return response()->json(['status' => 'already_processed']);
        }

        // 1. Strict Currency & Amount Verification in IPN
        if ($currency !== 'BDT') {
            Log::warning("SSLCommerz IPN currency mismatch for {$tranId}. Expected BDT, received: {$currency}");
            return response()->json(['status' => 'error', 'message' => 'Invalid currency'], 400);
        }

        if ($amount > 0 && abs((float) $payment->amount_bdt - $amount) > 0.01) {
            Log::warning("SSLCommerz IPN amount mismatch for {$tranId}. Expected: {$payment->amount_bdt}, Received: {$amount}");
            return response()->json(['status' => 'error', 'message' => 'Payment amount mismatch'], 400);
        }

        // 2. Validate Gateway Parameter Signature
        if (!$this->verifyGatewaySignature($request)) {
            Log::warning("SSLCommerz IPN signature verification failed for {$tranId}.");
            return response()->json(['status' => 'error', 'message' => 'Signature verification failed'], 403);
        }

        // 3. Status Verification (No Sandbox Bypass - SEC-G3-REV-02)
        if (!in_array($status, ['VALID', 'VALIDATED'], true)) {
            DB::transaction(function () use ($payment, $status, $request) {
                $locked = SubscriptionPayment::withoutGlobalScopes()->where('id', $payment->id)->lockForUpdate()->first();
                if ($locked && $locked->status === 'pending') {
                    $locked->update([
                        'status' => 'rejected',
                        'rejection_reason' => "IPN status: {$status}",
                        'gateway_payload' => $request->all(),
                    ]);
                }
            });

            return response()->json(['status' => 'rejected', 'message' => "Transaction status [{$status}] is not valid"], 422);
        }

        if (!$valId) {
            return response()->json(['status' => 'error', 'message' => 'Missing val_id'], 400);
        }

        // 4. Authoritative Server-to-Server Order Validation API Call (SEC-G3-REV-01)
        $validation = $validator->validateTransaction($payment, $valId);
        if (!$validation['is_valid']) {
            DB::transaction(function () use ($payment, $validation, $request) {
                $locked = SubscriptionPayment::withoutGlobalScopes()->where('id', $payment->id)->lockForUpdate()->first();
                if ($locked && $locked->status === 'pending') {
                    $locked->update([
                        'status' => 'rejected',
                        'rejection_reason' => $validation['reason'],
                        'gateway_payload' => $validation['payload'] ?? $request->all(),
                    ]);
                }
            });

            return response()->json([
                'status' => 'error',
                'message' => $validation['reason'],
            ], 422);
        }

        // 5. Concurrency-Safe Payment Approval within isolated Tenant Context (SEC-G4-PRI-01)
        $team = Team::withoutGlobalScopes()->find($payment->team_id);
        if ($team) {
            $tenantContext->runWithTenant($team, function () use ($service, $payment, $valId, $validation, $request) {
                DB::transaction(function () use ($service, $payment, $valId, $validation, $request) {
                    $lockedPayment = SubscriptionPayment::withoutGlobalScopes()
                        ->where('id', $payment->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedPayment) {
                        return;
                    }

                    // Idempotent: already approved by concurrent IPN or redirect
                    if ($lockedPayment->status === 'approved') {
                        return;
                    }

                    $lockedPayment->update([
                        'trx_id' => $valId,
                        'gateway_payload' => $validation['payload'] ?? $request->all(),
                    ]);

                    $service->approvePayment($lockedPayment, null);
                });
            });
        }

        return response()->json(['status' => 'success', 'message' => 'Payment approved']);
    }

    /**
     * Verify SSLCommerz payload signature (verify_sign / verify_key).
     */
    protected function verifyGatewaySignature(Request $request): bool
    {
        $isSandbox = config('services.sslcommerz.sandbox', true);
        $verifySign = $request->input('verify_sign');
        $verifyKey = $request->input('verify_key');
        $storePasswd = config('services.sslcommerz.store_password', 'sandbox_pass');

        // If in production mode, signature MUST be present and valid
        if (!$isSandbox) {
            if (!$verifySign || !$verifyKey) {
                return false;
            }
        }

        // When verify_sign and verify_key are present, compute MD5 hash validation
        if ($verifySign && $verifyKey) {
            $keyArray = explode(',', $verifyKey);
            $hashString = '';
            foreach ($keyArray as $key) {
                $trimmedKey = trim($key);
                $hashString .= $trimmedKey . '=' . $request->input($trimmedKey, '') . '&';
            }
            $hashString .= 'store_passwd=' . md5($storePasswd);
            $computedSign = md5($hashString);

            return hash_equals($computedSign, $verifySign);
        }

        // In sandbox without signature provided, pass through to authoritative validation
        return true;
    }
}
