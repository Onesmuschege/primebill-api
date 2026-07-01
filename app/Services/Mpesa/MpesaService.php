<?php

namespace App\Services\Mpesa;

use App\Jobs\ActivateNetworkAccessJob;
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
    // STK Callback — idempotency-safe (unchanged locking/dedup logic)
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

            $metadata  = $body['CallbackMetadata']['Item'] ?? [];
            $amount    = $this->getMetadataValue($metadata, 'Amount');
            $mpesaCode = $this->getMetadataValue($metadata, 'MpesaReceiptNumber');
            $phone     = $this->getMetadataValue($metadata, 'PhoneNumber');

            $reactivateClientId = null;

            DB::transaction(function () use (
                $checkoutId, $resultCode, $resultDesc,
                $amount, $mpesaCode, $phone, $payload, &$reactivateClientId
            ) {
                $tx = MpesaTransaction::where('checkout_request_id', $checkoutId)
                    ->lockForUpdate()
                    ->first();

                if (!$tx) {
                    Log::warning('STK Callback: unknown CheckoutRequestID', ['checkout_id' => $checkoutId]);
                    return;
                }

                if ($tx->status === 'completed' || $tx->status === 'failed') {
                    Log::info('STK Callback: duplicate callback ignored', [
                        'checkout_id' => $checkoutId,
                        'status'      => $tx->status,
                    ]);
                    return;
                }

                $isSuccess = (int) $resultCode === 0;

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

                if ($tx->invoice_id) {
                    Invoice::whereKey($tx->invoice_id)->lockForUpdate()->first();
                }

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

                // Flag this client for reactivation check AFTER the transaction
                // commits — we deliberately do it outside DB::transaction() so
                // the invoice/payment locks are released before we query for
                // remaining overdue invoices below.
                $reactivateClientId = $tx->client_id;
            });

            if ($reactivateClientId) {
                $this->reactivateIfClear($reactivateClientId);
            }

            return true;

        } catch (Exception $e) {
            if ($this->isDuplicateKeyException($e)) {
                Log::warning('STK Callback: duplicate payment blocked by unique constraint', [
                    'checkout_id' => $checkoutId ?? null,
                    'message'     => $e->getMessage(),
                ]);
                return true;
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

            $reactivateClientId = null;

            DB::transaction(function () use ($amount, $mpesaCode, $phone, $account, &$reactivateClientId) {
                $client = Client::where('phone', 'like', '%' . substr($phone, -9))->first();

                if (!$client) {
                    Log::warning('C2B Confirmation: no client found for phone', ['phone' => $phone]);
                    return;
                }

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
                    'idempotency_key' => $mpesaCode,
                ], null);

                $reactivateClientId = $client->id;
            });

            if ($reactivateClientId) {
                $this->reactivateIfClear($reactivateClientId);
            }

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
    // Reactivation — mirrors App\Console\Commands\ReactivatePaidAccounts,
    // scoped to a single client and triggered immediately on payment instead
    // of waiting for the next scheduled run. This is the only piece that was
    // actually missing: ReactivatePaidAccounts only catches this client on
    // its next cron tick, so without this, a client who just paid stays
    // offline until that job happens to run.
    // -------------------------------------------------------------------------

    private function reactivateIfClear(int $clientId): void
    {
        $client = Client::find($clientId);

        if (!$client || $client->status !== 'suspended') {
            return;
        }

        $hasOverdue = Invoice::where('client_id', $clientId)
            ->whereIn('status', ['overdue', 'unpaid'])
            ->where('due_date', '<', now())
            ->exists();

        if ($hasOverdue) {
            return;
        }

        foreach ($client->accounts()->where('status', 'suspended')->get() as $account) {
            $account->update(['status' => 'active']);
            ActivateNetworkAccessJob::dispatch($account->id);
        }

        $client->update(['status' => 'active']);

        Log::info('MpesaService: client reactivated immediately after payment', [
            'client_id' => $clientId,
        ]);
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

    private function isDuplicateKeyException(Exception $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, '1062')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}