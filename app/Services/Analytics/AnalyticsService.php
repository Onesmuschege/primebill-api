<?php

namespace App\Services\Analytics;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Return all analytics consumed by the frontend.
     */
    public function getIncomeAnalytics(): array
    {
        return [
            'monthly'         => $this->monthlyRevenue(),
            'client_growth'   => $this->clientGrowth(),
            'payment_methods' => $this->paymentMethods(),
        ];
    }

    /**
     * --------------------------------------------------------------------------
     * Monthly Revenue (Last 12 Months)
     * --------------------------------------------------------------------------
     */
    protected function monthlyRevenue(): array
    {
        $months = collect();

        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);

            $months->push([
                'month' => $date->format('M'),
                'year'  => $date->year,
                'key'   => $date->format('Y-m'),
                'total' => 0,
            ]);
        }

        $payments = Payment::selectRaw("
                TO_CHAR(created_at, 'YYYY-MM') as ym,
                SUM(amount) as total
            ")
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        return $months->map(function ($month) use ($payments) {

            if (isset($payments[$month['key']])) {
                $month['total'] = (float) $payments[$month['key']]->total;
            }

            unset($month['key']);
            unset($month['year']);

            return $month;
        })->values()->toArray();
    }

    /**
     * --------------------------------------------------------------------------
     * Client Growth
     * --------------------------------------------------------------------------
     */
    protected function clientGrowth(): array
    {
        $months = collect();

        for ($i = 11; $i >= 0; $i--) {

            $date = now()->subMonths($i);

            $months->push([
                'month' => $date->format('M'),
                'key'   => $date->format('Y-m'),
                'count' => 0,
            ]);
        }

        $clients = Client::selectRaw("
                TO_CHAR(created_at, 'YYYY-MM') as ym,
                COUNT(*) as total
            ")
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        return $months->map(function ($month) use ($clients) {

            if (isset($clients[$month['key']])) {
                $month['count'] = (int) $clients[$month['key']]->total;
            }

            unset($month['key']);

            return $month;
        })->values()->toArray();
    }

    /**
     * --------------------------------------------------------------------------
     * Payment Methods
     * --------------------------------------------------------------------------
     */
    protected function paymentMethods(): array
    {
        return Payment::select(
                'method',
                DB::raw('SUM(amount) as total')
            )
            ->where('status', 'completed')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) {

                return [
                    'method' => ucfirst($row->method),
                    'total'  => (float) $row->total,
                ];

            })
            ->values()
            ->toArray();
    }

    /**
     * --------------------------------------------------------------------------
     * Optional Summary
     * (Useful later for dashboard widgets)
     * --------------------------------------------------------------------------
     */
    public function summary(): array
    {
        return [

            'total_revenue' => (float) Payment::where('status', 'completed')
                ->sum('amount'),

            'monthly_revenue' => (float) Payment::where('status', 'completed')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->sum('amount'),

            'today_revenue' => (float) Payment::where('status', 'completed')
                ->whereDate('created_at', today())
                ->sum('amount'),

            'total_clients' => Client::count(),

            'active_accounts' => ClientAccount::where('status', 'active')->count(),

            'inactive_accounts' => ClientAccount::where('status', 'inactive')->count(),

            'suspended_accounts' => ClientAccount::where('status', 'suspended')->count(),

        ];
    }
}