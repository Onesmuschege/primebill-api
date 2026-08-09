<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PaymentAllocationService
 *
 * Splits a single collected payment across one or more invoices (the classic
 * "underpayment" / bulk-payment case where one cash/M-Pesa payment should be
 * applied to several open invoices). Every allocation is:
 *
 *   - idempotent (IdempotencyService, scope `payment.allocation`)
 *   - tenant-scoped (BelongsToTenant on the model)
 *   - ledger-backed (a balanced double-entry pair per allocation via
 *     LedgerService::postPair) so the financial ledger always stays in
 *     balance while a payment is reclassified onto specific invoices.
 *   - reversible (a reversal posts the mirror ledger pair and flips the row
 *     to `reversed`).
 *
 * The ledger is the single source of truth. This service never creates
 * ledger entries outside LedgerService.
 */
class PaymentAllocationService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected IdempotencyService $idempotencyService
    ) {}

    /**
     * Allocate a payment across one or more invoices.
     *
     * @param array $data  [
     *     'payment_id' => int (required),
     *     'client_id'  => int (required),
     *     'allocations'=> array of ['invoice_id' => int, 'amount' => float],
     *     'reference'  => string|null,
     *     'idempotency_key' => string|null,
     * ]
     * @return PaymentAllocation[] the created allocations (with relations)
     */
    public function allocate(array $data, ?int $userId = null): array
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        $ids = $this->idempotencyService->run(
            'payment.allocation',
            $idempotencyKey,
            function () use ($data, $userId) {
                return DB::transaction(function () use ($data, $userId) {
                    $payment = Payment::findOrFail($data['payment_id']);

                    if ($payment->status !== 'completed') {
                        throw new RuntimeException('Cannot allocate a payment that is not completed.');
                    }

                    if ((int) $payment->client_id !== (int) $data['client_id']) {
                        throw new RuntimeException('Payment does not belong to this client.');
                    }

                    $allocations = $data['allocations'];

                    // Guard: must provide a list of invoice/amount pairs.
                    if (!is_array($allocations) || count($allocations) === 0) {
                        throw new RuntimeException('At least one allocation is required.');
                    }

                    // Validate that the total allocation does not exceed the
                    // available (unallocated) portion of the payment.
                    $alreadyAllocated = (float) PaymentAllocation::where('payment_id', $payment->id)
                        ->where('status', 'allocated')
                        ->sum('amount');

                    $available = round((float) $payment->amount - $alreadyAllocated, 2);
                    $totalToAllocate = 0.0;

                    $created = [];

                    foreach ($allocations as $line) {
                        $invoice = Invoice::find($line['invoice_id']);

                        if (!$invoice) {
                            throw new RuntimeException('Invoice not found: ' . $line['invoice_id']);
                        }

                        if ((int) $invoice->client_id !== (int) $payment->client_id) {
                            throw new RuntimeException('Invoice does not belong to this client.');
                        }

                        $amount = round((float) ($line['amount'] ?? 0), 2);

                        if ($amount <= 0) {
                            throw new RuntimeException('Allocation amount must be positive.');
                        }

                        $totalToAllocate = round($totalToAllocate + $amount, 2);
                    }

                    if ($totalToAllocate > $available) {
                        throw new RuntimeException(
                            'Total allocation ' . $totalToAllocate
                            . ' exceeds the available payment amount ' . $available . '.'
                        );
                    }

                    // Create each allocation + its balanced ledger pair.
                    foreach ($allocations as $line) {
                        $invoice = Invoice::find($line['invoice_id']);
                        $amount  = round((float) ($line['amount'] ?? 0), 2);

                        $allocation = PaymentAllocation::create([
                            'tenant_id'    => $payment->tenant_id,
                            'payment_id'   => $payment->id,
                            'invoice_id'   => $invoice->id,
                            'client_id'    => $payment->client_id,
                            'amount'       => $amount,
                            'currency'     => $data['currency'] ?? 'KES',
                            'status'       => 'allocated',
                            'reference'    => $data['reference'] ?? null,
                            'meta'         => $data['meta'] ?? null,
                            'recorded_by'  => $userId,
                        ]);

                        // Balanced ledger pair reclassifying the payment onto
                        // this specific invoice. payment_credit already reduced
                        // AR; this allocation traces exactly which invoice the
                        // collected money belongs to and keeps the ledger
                        // balanced (invoice-specific receivable remains zero).
                        $this->ledgerService->postPair(
                            [
                                'client_id'   => $payment->client_id,
                                'payment_id'  => $payment->id,
                                'invoice_id'  => $invoice->id,
                                'entry_type'  => 'allocation_debit',
                                'account_type' => 'accounts_receivable',
                                'amount'      => $amount,
                                'description' => 'Payment allocation to invoice',
                                'meta'        => ['allocation_id' => $allocation->id, 'method' => $payment->method],
                            ],
                            [
                                'client_id'   => $payment->client_id,
                                'payment_id'  => $payment->id,
                                'invoice_id'  => $invoice->id,
                                'entry_type'  => 'allocation_credit',
                                'account_type' => 'accounts_receivable',
                                'amount'      => $amount,
                                'description' => 'Payment allocation to invoice',
                                'meta'        => ['allocation_id' => $allocation->id, 'method' => $payment->method],
                            ],
                            $userId
                        );

                        $created[] = $allocation;
                    }

                    return array_map(fn (PaymentAllocation $a) => $a->id, $created);
                });
            }
        );

        // Re-resolve the allocated models with relations loaded.
        return PaymentAllocation::with('payment', 'invoice', 'client')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * List payment allocations with optional filters.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listAllocations(Request $request)
    {
        $query = PaymentAllocation::with('payment', 'invoice', 'client');

        if ($request->filled('payment_id')) {
            $query->where('payment_id', $request->payment_id);
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
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

    /**
     * Reverse a single payment allocation and post the mirror ledger pair.
     */
    public function reverse(PaymentAllocation $allocation, ?int $userId = null, ?string $reason = null): PaymentAllocation
    {
        if ($allocation->isReversed()) {
            throw new RuntimeException('Payment allocation is already reversed.');
        }

        return DB::transaction(function () use ($allocation, $userId, $reason) {
            $this->ledgerService->postPair(
                [
                    'client_id'    => $allocation->client_id,
                    'payment_id'   => $allocation->payment_id,
                    'invoice_id'   => $allocation->invoice_id,
                    'entry_type'   => 'allocation_reversal_credit',
                    'account_type' => 'accounts_receivable',
                    'amount'       => (float) $allocation->amount,
                    'description'  => 'Payment allocation reversed',
                    'meta'         => ['allocation_id' => $allocation->id, 'reason' => $reason],
                ],
                [
                    'client_id'    => $allocation->client_id,
                    'payment_id'   => $allocation->payment_id,
                    'invoice_id'   => $allocation->invoice_id,
                    'entry_type'   => 'allocation_reversal_debit',
                    'account_type' => 'accounts_receivable',
                    'amount'       => (float) $allocation->amount,
                    'description'  => 'Payment allocation reversed',
                    'meta'         => ['allocation_id' => $allocation->id, 'reason' => $reason],
                ],
                $userId
            );

            $allocation->update([
                'status'      => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'meta'        => array_merge($allocation->meta ?? [], ['reason' => $reason]),
            ]);

            return $allocation->fresh(['payment', 'invoice', 'client']);
        });
    }

    /**
     * Sum of allocations currently applied to a given invoice.
     */
    public function invoiceAllocatedTotal(Invoice $invoice): float
    {
        return round((float) PaymentAllocation::where('invoice_id', $invoice->id)
            ->where('status', 'allocated')
            ->sum('amount'), 2);
    }

    /**
     * Sum of allocations still applied to a given payment.
     */
    public function paymentAllocatedTotal(Payment $payment): float
    {
        return round((float) PaymentAllocation::where('payment_id', $payment->id)
            ->where('status', 'allocated')
            ->sum('amount'), 2);
    }
}

