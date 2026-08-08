<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RefundService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected IdempotencyService $idempotencyService
    ) {}

    public function issue(array $data, ?int $userId = null): Refund
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;
        unset($data['idempotency_key']);

        $refundId = $this->idempotencyService->run(
            'refund.issue',
            $idempotencyKey,
            function () use ($data, $userId) {
                return DB::transaction(function () use ($data, $userId) {
                    $payment = Payment::findOrFail($data['payment_id']);

                    if ($payment->status !== 'completed') {
                        throw new RuntimeException('Cannot refund a payment that is not completed.');
                    }

                    if ($payment->client_id !== $data['client_id']) {
                        throw new RuntimeException('Payment does not belong to this client.');
                    }

                    $amount = (float) ($data['amount'] ?? $payment->amount);

                    if ($amount <= 0) {
                        throw new RuntimeException('Refund amount must be positive.');
                    }

                    // Prevent over-refunding: sum of completed refunds + this amount <= payment amount
                    $alreadyRefunded = (float) Refund::where('payment_id', $payment->id)
                        ->where('status', 'completed')
                        ->sum('amount');

                    if ($alreadyRefunded + $amount > (float) $payment->amount) {
                        throw new RuntimeException('Refund amount exceeds the payment amount.');
                    }

                    $refund = Refund::create([
                        'tenant_id'      => $data['tenant_id'] ?? auth()->user()?->tenant_id,
                        'client_id'      => $payment->client_id,
                        'payment_id'     => $payment->id,
                        'invoice_id'     => $payment->invoice_id,
                        'refund_number'  => $this->generateNumber(),
                        'amount'         => $amount,
                        'currency'       => $data['currency'] ?? 'KES',
                        'method'         => $data['method'] ?? 'other',
                        'reference'      => $data['reference'] ?? null,
                        'status'         => 'completed',
                        'reason'         => $data['reason'] ?? null,
                        'reference_uuid' => (string) Str::uuid(),
                        'recorded_by'    => $userId,
                    ]);

                    // Post balanced ledger reversal pair
                    $this->ledgerService->postRefundIssued(
                        $refund->client_id,
                        $amount,
                        $refund->payment_id,
                        $refund->invoice_id,
                        $userId,
                        'Refund ' . $refund->refund_number . ' issued',
                        ['refund_number' => $refund->refund_number, 'reason' => $refund->reason]
                    );

                    return $refund->id;
                });
            }
        );

        $refund = Refund::with('client', 'payment', 'invoice')->find($refundId);

        if (!$refund) {
            throw new RuntimeException('Failed to resolve refund after processing.');
        }

        return $refund;
    }

    public function reverse(Refund $refund, ?int $userId = null, ?string $reason = null): Refund
    {
        if ($refund->isReversed()) {
            throw new RuntimeException('Refund is already reversed.');
        }

        return DB::transaction(function () use ($refund, $userId, $reason) {
            $this->ledgerService->postRefundReversal(
                $refund->client_id,
                (float) $refund->amount,
                $refund->payment_id,
                $refund->invoice_id,
                $userId,
                'Refund ' . $refund->refund_number . ' reversed',
                ['refund_number' => $refund->refund_number, 'reason' => $reason]
            );

            $refund->update([
                'status'      => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
            ]);

            return $refund;
        });
    }

    public function generateNumber(): string
    {
        $prefix = 'RF';
        $year   = date('Y');

        $last = Refund::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        $number = $last
            ? (intval(substr($last->refund_number, -6)) + 1)
            : 1;

        return $prefix . '-' . $year . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
}
