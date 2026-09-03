<?php

namespace App\Services\Billing;

use App\Jobs\ActivateNetworkAccessJob;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected IdempotencyService $idempotencyService
    ) {}

    public function getAllPayments(Request $request)
    {
        $query = Payment::with('client', 'invoice');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        return $query->orderBy('created_at', 'desc')
                     ->paginate($request->per_page ?? 15);
    }

    public function recordPayment(array $data, int|null $userId): Payment
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;
        unset($data['idempotency_key']);

        $paymentId = $this->idempotencyService->run(
            'payment.record',
            $idempotencyKey,
            function () use ($data, $userId) {

                // Deduplicate by reference
                if (!empty($data['reference'])) {
                    $existing = Payment::where('method', $data['method'])
                        ->where('reference', $data['reference'])
                        ->first();
                    if ($existing) return $existing->id;
                }

                // Deduplicate by M-Pesa code
                if (!empty($data['mpesa_code'])) {
                    $existing = Payment::where('mpesa_code', $data['mpesa_code'])->first();
                    if ($existing) return $existing->id;
                }

                $payment = DB::transaction(function () use ($data, $userId) {
                    $data['status']      = 'completed';
                    $data['recorded_by'] = $userId;

                    $payment = Payment::create($data);

                    $this->ledgerService->postPaymentCredit($payment, $userId);

                    // Update invoice status if payment is linked to one
                    if (!empty($data['invoice_id'])) {
                        $invoice = Invoice::find($data['invoice_id']);

                        if ($invoice) {
                            $totalPaid = Payment::where('invoice_id', $invoice->id)
                                ->where('status', 'completed')
                                ->sum('amount');

                            if ($totalPaid >= $invoice->total) {
                                $invoice->update([
                                    'status'  => 'paid',
                                    'paid_at' => now(),
                                ]);
                                $this->extendClientAccount($data['client_id'], $invoice);
                            } elseif ($totalPaid > 0) {
                                // Partial payment
                                $invoice->update(['status' => 'partial']);
                            }
                        }
                    }

                    SystemLog::create([
                        'user_id'    => $userId,
                        'action'     => 'recorded payment',
                        'model'      => 'Payment',
                        'model_id'   => $payment->id,
                        'new_values' => $data,
                    ]);

                    return $payment;
                });

                return $payment->id;
            }
        );

        $payment = Payment::with('client', 'invoice')->find($paymentId);

        if (!$payment) {
            throw new RuntimeException('Failed to resolve payment after processing.');
        }

        return $payment;
    }

    public function deletePayment(Payment $payment, int|null $userId): void
    {
        DB::transaction(function () use ($payment, $userId) {
            if ($payment->invoice_id) {
                $invoice = Invoice::find($payment->invoice_id);

                if ($invoice) {
                    // Recalculate remaining paid amount excluding this payment
                    $remainingPaid = Payment::where('invoice_id', $invoice->id)
                        ->where('status', 'completed')
                        ->where('id', '!=', $payment->id)
                        ->sum('amount');

                    if ($remainingPaid <= 0) {
                        $status = now()->gt($invoice->due_date) ? 'overdue' : 'unpaid';
                    } elseif ($remainingPaid < $invoice->total) {
                        $status = 'partial';
                    } else {
                        $status = 'paid';
                    }

                    $invoice->update([
                        'status'  => $status,
                        'paid_at' => $status === 'paid' ? $invoice->paid_at : null,
                    ]);
                }
            }

            $this->ledgerService->postPaymentReversal($payment, $userId);

            SystemLog::create([
                'user_id'    => $userId,
                'action'     => 'deleted payment',
                'model'      => 'Payment',
                'model_id'   => $payment->id,
                'old_values' => $payment->toArray(),
            ]);

            $payment->delete();
        });
    }

    private function extendClientAccount(int $clientId, Invoice $invoice): void
    {
        $account = $this->resolveAccountForInvoice($invoice, $clientId);

        if (!$account || !$account->plan) return;

        $validityDays  = $account->plan->validity_days ?? 30;
        $currentExpiry = $account->expiry_date ?? now();

        $newExpiry = $currentExpiry < now()
            ? now()->addDays($validityDays)
            : $currentExpiry->copy()->addDays($validityDays);

        // Payment-driven reactivation flows through the lifecycle authority
        // (never forced) so administratively held services are not restored
        // and `status`/`service_state` stay in lockstep (SL2).
        $account->update(['expiry_date' => $newExpiry]);

        Log::info('PaymentService: service extended by payment', [
            'payment_invoice_id' => $invoice->id,
            'client_id'          => $clientId,
            'client_account_id'  => $account->id,
            'new_expiry'         => $newExpiry->toDateTimeString(),
        ]);

        ActivateNetworkAccessJob::dispatch(
            $account->id,
            $account->tenant_id,
            false,
            'Payment received — billing reactivation'
        );
    }

    /**
     * Resolve the exact service that owns an invoice (Commercial Transaction
     * Identity — Section 9).
     *
     * The invoice must name the service. Payments allocated to a multi-service
     * customer must extend exactly that service, never "the first account".
     *
     * Only invoices that predate service linking fall back to the client's
     * first usable connection — and the returned account is still scoped to
     * the invoice's client so a cross-tenant leak is impossible.
     */
    private function resolveAccountForInvoice(Invoice $invoice, int $clientId): ?ClientAccount
    {
        if ($invoice->client_account_id) {
            return ClientAccount::with('plan')
                ->where('id', $invoice->client_account_id)
                ->where('client_id', $clientId)
                ->first();
        }

        return ClientAccount::with('plan')
            ->where('client_id', $clientId)
            ->where('status', '!=', 'inactive')
            ->first();
    }

    /**
     * All-time payment summary (not date-scoped).
     * Card labels on the frontend should read "Total Payments" / "M-Pesa" / "Cash"
     * rather than "Today's Total" to match this.
     */
    public function getDailySummary(): array
    {
        return [
            'total' => Payment::where('status', 'completed')->sum('amount'),
            'count' => Payment::where('status', 'completed')->count(),
            'mpesa' => Payment::where('method', 'mpesa')
                ->where('status', 'completed')->sum('amount'),
            'cash'  => Payment::where('method', 'cash')
                ->where('status', 'completed')->sum('amount'),
        ];
    }
}
