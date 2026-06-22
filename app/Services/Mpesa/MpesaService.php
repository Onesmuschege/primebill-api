<?php

namespace App\Services\Mpesa;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\MpesaTransaction;
use App\Services\Billing\PaymentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class MpesaService
{
    protected string $baseUrl;
    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $env           = config('mpesa.env');
        $this->baseUrl = config("mpesa.base_url.{$env}");
        $this->paymentService = $paymentService;
    }

    // -------------------------------------------------------------------------
    // OAuth
    // -------------------------------------------------------------------------

    public function getAccessToken(): string
    {
        $consumerKey    = config('mpesa.consumer_key');
        $consumerSecret = config('mpesa.consumer_secret');
        $credentials    = base64_encode("{$consumerKey}:{$consumerSecret}");

        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}",
        ])->get("{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials");

        if (!$response->successful()) {
            throw new Exception('Failed to fetch M-Pesa access token: ' . $response->body());
        }

        return $response->json('access_token');
    }

    // -------------------------------------------------------------------------
    // STK Push
    // -------------------------------------------------------------------------

    public function stkPush(string $phone, float $amount, int $invoiceId, string $accountRef): array
    {
        try {
            $invoice = Invoice::find($invoiceId);
            if (!$invoice) {
                return ['error' => 'Invoice not found'];
            }

            $token     = $this->getAccessToken();
            $timestamp = now()->format('YmdHis');
            $shortcode = config('mpesa.shortcode');
            $passkey   = config('mpesa.passkey');
            $password  = base64_encode($shortcode . $passkey . $timestamp);
            $phone     = $this->formatPhone($phone);

            $requestPayload = [
                'BusinessShortCode' => $shortcode,
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'TransactionType'   => 'CustomerPayBillOnline',
                'Amount'            => (int) $amount,
                'PartyA'            => $phone,
                'PartyB'            => $shortcode,
                'PhoneNumber'       => $phone,
                'CallBackURL'       => config('mpesa.callback_url'),
                'AccountReference'  => $accountRef,
                'TransactionDesc'   => 'Internet Bill Payment',
            ];

            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", $requestPayload);

            $json = $response->json() ?? [];

            // Track STK request so the callback can reconcile against it.
            MpesaTransaction::create([
                'client_id'           => $invoice->client_id,
                'invoice_id'          => $invoice->id,
                'phone'               => $phone,
                'amount'              => (int) $amount,
                'account_reference'   => $accountRef,
                'merchant_request_id' => $json['MerchantRequestID'] ?? null,
                'checkout_request_id' => $json['CheckoutRequestID'] ?? null,
                'result_code'         => $json['ResponseCode'] ?? null,
                'result_desc'         => $json['ResponseDescription'] ?? ($json['errorMessage'] ?? null),
                'status'              => isset($json['CheckoutRequestID']) ? 'pending' : 'failed',
                'raw_request'         => $requestPayload,
            ]);

            return $json;

        } catch (Exception $e) {
            Log::error('STK Push Error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ['error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // STK Callback — idempotency-safe
    //
    // RACE CONDITION FIXED:
    // The original code checked $tx->status === 'completed' BEFORE acquiring any
    // lock. Under concurrent Safaricom retries two processes could both pass that
    // check, both enter DB::transaction(), and both call recordPayment() — producing
    // a duplicate payment row.
    //
    // THE FIX:
    // 1. Wrap the ENTIRE check-and-update inside a single DB::transaction().
    // 2. Re-fetch the MpesaTransaction row with lockForUpdate() as the FIRST
    //    statement inside that transaction. The DB engine now serialises concurrent
    //    callbacks — the second one blocks on the lock until the first commits,
    //    then re-reads status = 'completed' and exits.
    // 3. The unique index on payments.idempotency_key is the final safety net:
    //    even if two processes somehow both get past the lock, the DB rejects the
    //    second INSERT with a unique constraint violation rather than silently
    //    creating a duplicate payment row.
    // -------------------------------------------------------------------------

    public function handleStkCallback(array $payload): bool
    {
        try {
            $body = $payload['Body']['stkCallback'] ?? null;
            if (!$body) {
                Log::warning('STK Callback: missing Body.stkCallback', ['payload' => $payload]);
                return false;
            }

            $resultCode = $body['ResultCode'] ?? null;
            $checkoutId = $body['CheckoutRequestID'] ?? null;
            $resultDesc = $body['ResultDesc'] ?? null;

            if (!$checkoutId) {
                Log::warning('STK Callback: missing CheckoutRequestID');
                return false;
            }

            // Pull the metadata out before entering the transaction so we're not
            // doing work inside the locked section unnecessarily.
            $metadata  = $body['CallbackMetadata']['Item'] ?? [];
            $amount    = $this->getMetadataValue($metadata, 'Amount');
            $mpesaCode = $this->getMetadataValue($metadata, 'MpesaReceiptNumber');
            $phone     = $this->getMetadataValue($metadata, 'PhoneNumber');

            // ---------------------------------------------------------------
            // CRITICAL SECTION — everything inside here is serialised per
            // CheckoutRequestID by the database row lock.
            // ---------------------------------------------------------------
            DB::transaction(function () use (
                $checkoutId, $resultCode, $resultDesc,
                $amount, $mpesaCode, $phone, $payload
            ) {
                // Step 1: Acquire an exclusive lock on this MpesaTransaction row.
                // Any concurrent callback for the same CheckoutRequestID will block
                // here until we commit, then re-read status = 'completed' and abort.
                $tx = MpesaTransaction::where('checkout_request_id', $checkoutId)
                    ->lockForUpdate()
                    ->first();

                if (!$tx) {
                    Log::warning('STK Callback: unknown CheckoutRequestID', ['checkout_id' => $checkoutId]);
                    return; // Nothing to process — let Safaricom retry if it wants.
                }

                // Step 2: Re-check terminal status AFTER acquiring the lock.
                // This is the authoritative check — not the one before the transaction.
                if ($tx->status === 'completed' || $tx->status === 'failed') {
                    Log::info('STK Callback: duplicate callback ignored', [
                        'checkout_id' => $checkoutId,
                        'status'      => $tx->status,
                    ]);
                    return;
                }

                // Step 3: Resolve final status — do this before any writes.
                $isSuccess = (int) $resultCode === 0;

                // Step 4: Update the transaction record with everything we know.
                // We go straight to the terminal state ('completed' or 'failed') —
                // never back to 'pending'. The intermediate write in the original
                // code left a window where status was 'pending' after the update
                // but before the payment was recorded.
                $tx->update([
                    'result_code'          => $resultCode,
                    'result_desc'          => $resultDesc,
                    'raw_callback'         => $payload,
                    'mpesa_receipt_number' => $mpesaCode ?? $tx->mpesa_receipt_number,
                    'phone'                => $phone ? (string) $phone : $tx->phone,
                    'amount'               => $amount ?? $tx->amount,
                    'status'               => $isSuccess ? 'completed' : 'failed',
                ]);

                if (!$isSuccess) {
                    Log::warning('STK Push failed by user or network', [
                        'checkout_id' => $checkoutId,
                        'result_desc' => $resultDesc,
                        'result_code' => $resultCode,
                    ]);
                    return;
                }

                // Step 5: Lock the invoice row to prevent concurrent overpay race.
                // This is a separate from the MpesaTransaction lock — we need both.
                if ($tx->invoice_id) {
                    Invoice::whereKey($tx->invoice_id)->lockForUpdate()->first();
                }

                // Step 6: Record the payment.
                // The idempotency_key maps to the UNIQUE index on payments.idempotency_key.
                // If this INSERT somehow races past both locks (should not happen), the DB
                // unique constraint rejects the duplicate and the outer try/catch logs it.
                $this->paymentService->recordPayment([
                    'client_id'       => $tx->client_id,
                    'invoice_id'      => $tx->invoice_id,
                    'amount'          => $amount ?? $tx->amount,
                    'method'          => 'mpesa',
                    'mpesa_code'      => $mpesaCode,
                    'reference'       => $checkoutId,
                    'idempotency_key' => $mpesaCode ?? $checkoutId,
                ], null);

                Log::info('STK Callback: payment recorded', [
                    'checkout_id'  => $checkoutId,
                    'mpesa_code'   => $mpesaCode,
                    'amount'       => $amount ?? $tx->amount,
                    'client_id'    => $tx->client_id,
                    'invoice_id'   => $tx->invoice_id,
                ]);
            });

            return true;

        } catch (Exception $e) {
            // Catch unique constraint violations here — they mean a duplicate payment
            // was already recorded. Log it and return true so Safaricom stops retrying.
            if ($this->isDuplicateKeyException($e)) {
                Log::warning('STK Callback: duplicate payment blocked by unique constraint', [
                    'checkout_id' => $checkoutId ?? null,
                    'message'     => $e->getMessage(),
                ]);
                return true; // Tell Safaricom we received it — no point in retrying.
            }

            Log::error('STK Callback Error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // C2B Confirmation
    // -------------------------------------------------------------------------

    public function handleC2BConfirmation(array $payload): bool
    {
        try {
            $amount    = $payload['TransAmount'];
            $mpesaCode = $payload['TransID'];
            $phone     = $payload['MSISDN'];
            $account   = $payload['BillRefNumber'];

            // C2B also needs idempotency — the same TransID should never be recorded twice.
            DB::transaction(function () use ($amount, $mpesaCode, $phone, $account) {
                $client = Client::where('phone', 'like', '%' . substr($phone, -9))->first();

                if (!$client) {
                    Log::warning('C2B Confirmation: no client found for phone', ['phone' => $phone]);
                    return;
                }

                // Lock the oldest unpaid invoice to prevent concurrent C2B races.
                $invoice = Invoice::where('client_id', $client->id)
                    ->where('status', 'unpaid')
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->first();

                $this->paymentService->recordPayment([
                    'client_id'       => $client->id,
                    'invoice_id'      => $invoice?->id,
                    'amount'          => $amount,
                    'method'          => 'mpesa',
                    'mpesa_code'      => $mpesaCode,
                    'reference'       => $account,
                    'idempotency_key' => $mpesaCode, // TransID is globally unique — safe dedup key.
                ], null);
            });

            return true;

        } catch (Exception $e) {
            if ($this->isDuplicateKeyException($e)) {
                Log::warning('C2B Confirmation: duplicate TransID blocked', [
                    'trans_id' => $payload['TransID'] ?? null,
                ]);
                return true;
            }

            Log::error('C2B Confirmation Error', ['message' => $e->getMessage()]);
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }

        return $phone;
    }

    private function getMetadataValue(array $items, string $name): mixed
    {
        foreach ($items as $item) {
            if ($item['Name'] === $name) {
                return $item['Value'];
            }
        }
        return null;
    }

    /**
     * Detect a MySQL/MariaDB duplicate key (unique constraint) violation.
     * PDO throws this as a generic Exception wrapping a QueryException;
     * the SQLSTATE for duplicate key is 23000 and the MySQL error code is 1062.
     */
    private function isDuplicateKeyException(Exception $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, '1062')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}