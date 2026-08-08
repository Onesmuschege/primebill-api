<?php

namespace App\Services\Billing;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class FinancialStatementService
{
    /**
     * Generate a trial balance from the ledger, grouped by account_type.
     *
     * @return array{accounts: array, total_debits: float, total_credits: float, balanced: bool}
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $query = LedgerEntry::query();

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $rows = $query->select(
                'account_type',
                DB::raw('SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as total_debits'),
                DB::raw('SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as total_credits')
            )
            ->groupBy('account_type')
            ->orderBy('account_type')
            ->get();

        $accounts = $rows->map(function ($row) {
            return [
                'account_type'  => $row->account_type,
                'total_debits'  => round((float) $row->total_debits, 2),
                'total_credits' => round((float) $row->total_credits, 2),
                'balance'       => round((float) $row->total_debits - (float) $row->total_credits, 2),
            ];
        });

        $totalDebits = round($accounts->sum('total_debits'), 2);
        $totalCredits = round($accounts->sum('total_credits'), 2);

        return [
            'accounts'      => $accounts,
            'total_debits'  => $totalDebits,
            'total_credits' => $totalCredits,
            'balanced'      => abs($totalDebits - $totalCredits) < 0.01,
        ];
    }

    /**
     * Revenue recognition report — revenue recognized per period.
     *
     * @return array{periods: array, total_revenue: float}
     */
    public function revenueRecognitionReport(?string $from = null, ?string $to = null): array
    {
        $query = LedgerEntry::where('account_type', 'revenue')
            ->where('direction', 'credit');

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $rows = $query->select(
                DB::raw('DATE_FORMAT(created_at, "%Y-%m") as period'),
                DB::raw('SUM(amount) as revenue')
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $periods = $rows->map(function ($row) {
            return [
                'period'  => $row->period,
                'revenue' => round((float) $row->revenue, 2),
            ];
        });

        return [
            'periods'       => $periods,
            'total_revenue' => round($periods->sum('revenue'), 2),
        ];
    }

    /**
     * Verify ledger balance invariance — sum of debits equals sum of credits.
     */
    public function verifyLedgerBalance(): bool
    {
        $debits = (float) LedgerEntry::where('direction', 'debit')->sum('amount');
        $credits = (float) LedgerEntry::where('direction', 'credit')->sum('amount');

        return abs($debits - $credits) < 0.01;
    }
}
