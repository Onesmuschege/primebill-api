<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentPlanService
{
    public function create(array $data, ?int $userId = null): PaymentPlan
    {
        return DB::transaction(function () use ($data, $userId) {
            $invoice = isset($data['invoice_id']) ? Invoice::find($data['invoice_id']) : null;

            if ($invoice && $invoice->client_id !== $data['client_id']) {
                throw new RuntimeException('Invoice does not belong to this client.');
            }

            $totalAmount = (float) ($data['total_amount'] ?? $invoice?->total ?? 0);
            $installmentCount = (int) ($data['installment_count'] ?? 1);

            if ($installmentCount < 1) {
                throw new RuntimeException('Installment count must be at least 1.');
            }

            $installmentAmount = round($totalAmount / $installmentCount, 2);
            $frequency = $data['frequency'] ?? 'monthly';
            $startsAt = $data['starts_at'] ?? now();

            $plan = PaymentPlan::create([
                'tenant_id'         => $data['tenant_id'] ?? auth()->user()?->tenant_id,
                'client_id'         => $data['client_id'],
                'invoice_id'        => $data['invoice_id'] ?? null,
                'total_amount'      => $totalAmount,
                'paid_amount'       => 0,
                'status'            => 'active',
                'installment_count' => $installmentCount,
                'frequency'         => $frequency,
                'starts_at'         => $startsAt,
                'ends_at'           => $data['ends_at'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $userId,
            ]);

            // Create installments
            $dueDate = $startsAt instanceof Carbon
                ? $startsAt
                : Carbon::parse($startsAt);

            for ($i = 1; $i <= $installmentCount; $i++) {
                // Last installment absorbs rounding remainder
                $amount = $i === $installmentCount
                    ? round($totalAmount - ($installmentAmount * ($installmentCount - 1)), 2)
                    : $installmentAmount;

                PaymentPlanInstallment::create([
                    'tenant_id'       => $plan->tenant_id,
                    'payment_plan_id' => $plan->id,
                    'sequence'        => $i,
                    'amount'          => $amount,
                    'paid_amount'     => 0,
                    'status'          => 'pending',
                    'due_date'        => $dueDate->copy()->addMonths($i - 1),
                ]);
            }

            return $plan->load('installments');
        });
    }

    public function recordInstallmentPayment(PaymentPlanInstallment $installment, float $amount, ?int $paymentId = null): PaymentPlanInstallment
    {
        return DB::transaction(function () use ($installment, $amount, $paymentId) {
            $plan = $installment->paymentPlan;

            if ($plan->status === 'completed') {
                throw new RuntimeException('Payment plan is already completed.');
            }

            if ($installment->status === 'paid') {
                throw new RuntimeException('Installment is already paid.');
            }

            $newPaid = round((float) $installment->paid_amount + $amount, 2);

            if ($newPaid > (float) $installment->amount) {
                throw new RuntimeException('Payment exceeds installment amount.');
            }

            $installment->update([
                'paid_amount' => $newPaid,
                'status'      => $newPaid >= (float) $installment->amount ? 'paid' : 'pending',
                'paid_at'     => $newPaid >= (float) $installment->amount ? now() : null,
                'payment_id'  => $paymentId,
            ]);

            $planPaid = round((float) $plan->paid_amount + $amount, 2);
            $plan->update([
                'paid_amount' => $planPaid,
                'status'      => $planPaid >= (float) $plan->total_amount ? 'completed' : 'active',
            ]);

            return $installment->fresh();
        });
    }

    public function markOverdueInstallments(): int
    {
        return PaymentPlanInstallment::where('status', 'pending')
            ->where('due_date', '<', now())
            ->update(['status' => 'overdue']);
    }

    public function getAgedDebtQueue(?int $days = null)
    {
        $query = PaymentPlan::where('status', 'active')
            ->with('client', 'invoice', 'installments');

        if ($days !== null) {
            $query->where('starts_at', '<', now()->subDays($days));
        }

        return $query->orderBy('starts_at')->get();
    }
}
