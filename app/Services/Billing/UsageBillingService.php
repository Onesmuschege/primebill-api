<?php

namespace App\Services\Billing;

use App\Models\ClientAccount;
use App\Models\RadiusSession;
use App\Models\UsageBillingRecord;
use Illuminate\Support\Facades\DB;

class UsageBillingService
{
    /**
     * Compute data usage for a client account in a given period (bytes).
     */
    public function getUsageBytes(int $clientAccountId, string $period): int
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        $start = now()->setDate($year, $month, 1)->startOfMonth();
        $end   = (clone $start)->addMonth()->subSecond();

        return (int) RadiusSession::where('client_account_id', $clientAccountId)
            ->whereBetween('session_start', [$start, $end])
            ->sum(DB::raw('bytes_in + bytes_out'));
    }

    /**
     * Compute overage for a client account in a period.
     *
     * @return array{bytes_used: int, bytes_included: int, bytes_overage: int, overage_amount: float}
     */
    public function computeOverage(int $clientAccountId, string $period, float $ratePerGb = 0): array
    {
        $account = ClientAccount::with('plan')->findOrFail($clientAccountId);

        $bytesUsed = $this->getUsageBytes($clientAccountId, $period);
        $bytesIncluded = $account->plan?->fup_limit
            ? (int) $account->plan->fup_limit * 1024 * 1024 // MB -> bytes
            : 0;

        $bytesOverage = max(0, $bytesUsed - $bytesIncluded);

        $overageGb = $bytesOverage / (1024 * 1024 * 1024);
        $overageAmount = round($overageGb * $ratePerGb, 2);

        return [
            'bytes_used'     => $bytesUsed,
            'bytes_included' => $bytesIncluded,
            'bytes_overage'  => $bytesOverage,
            'overage_amount' => $overageAmount,
        ];
    }

    /**
     * Record usage billing for a client account for a period.
     */
    public function recordUsage(int $clientAccountId, string $period, float $ratePerGb = 0): UsageBillingRecord
    {
        $account = ClientAccount::with('client')->findOrFail($clientAccountId);

        $usage = $this->computeOverage($clientAccountId, $period, $ratePerGb);

        return UsageBillingRecord::updateOrCreate(
            [
                'client_account_id' => $clientAccountId,
                'billing_period'    => $period,
            ],
            [
                'tenant_id'        => $account->tenant_id,
                'client_id'        => $account->client_id,
                'bytes_used'       => $usage['bytes_used'],
                'bytes_included'   => $usage['bytes_included'],
                'bytes_overage'    => $usage['bytes_overage'],
                'rate_per_gb'      => $ratePerGb,
                'overage_amount'   => $usage['overage_amount'],
                'status'           => $usage['overage_amount'] > 0 ? 'pending' : 'waived',
                'meta'             => ['plan_name' => $account->plan?->name],
            ]
        );
    }

    /**
     * Get all pending usage records for a period.
     */
    public function getPendingForPeriod(string $period)
    {
        return UsageBillingRecord::where('billing_period', $period)
            ->where('status', 'pending')
            ->with('client', 'clientAccount')
            ->get();
    }
}
