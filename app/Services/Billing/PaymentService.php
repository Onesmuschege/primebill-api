<?php

namespace App\Services\Billing;

use App\Jobs\ActivateNetworkAccessJob;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SystemLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $account = ClientAccount::where('client_id', $clientId)
            ->where('status', '!=', 'inactive')
            ->with('plan')
            ->first();

        if (!$account || !$account->plan) return;

        $validityDays  = $account->plan->validity_days ?? 30;
        $currentExpiry = $account->expiry_date ?? now();

        $newExpiry = $currentExpiry < now()
            ? now()->addDays($validityDays)
            : $currentExpiry->copy()->addDays($validityDays);

        $account->update([
            'status'      => 'active',
            'expiry_date' => $newExpiry,
        ]);

        ActivateNetworkAccessJob::dispatch($account->id);
    }

    public function getDailySummary(): array
    {
        $today = now()->toDateString();

        return [
            'total' => Payment::whereDate('created_at', $today)
                ->where('status', 'completed')->sum('amount'),
            'count' => Payment::whereDate('created_at', $today)
                ->where('status', 'completed')->count(),
            'mpesa' => Payment::whereDate('created_at', $today)
                ->where('method', 'mpesa')
                ->where('status', 'completed')->sum('amount'),
            'cash'  => Payment::whereDate('created_at', $today)
                ->where('method', 'cash')
                ->where('status', 'completed')->sum('amount'),
        ];
    }
}
