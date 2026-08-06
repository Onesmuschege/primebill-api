<?php

namespace App\Services\Dashboard;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\Router;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\Expenditure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    /**
     * Per-tenant dashboard stats cache TTL (seconds).
     */
    private const CACHE_TTL = 600; // 10 minutes

    public function getStats(): array
    {
        $tenantId = Tenant::current()?->id ?? 'global';

        return Cache::remember("dashboard:stats:{$tenantId}", self::CACHE_TTL, function () {
            $today = now()->toDateString();

            return [
                'income_today'    => $this->getIncomeToday($today),
                'income_month'    => $this->getIncomeThisMonth(),
                'active_users'    => $this->getActiveUsers(),
                'total_users'     => $this->safe(fn() => Client::count(), 0),
                'tickets'         => $this->getTicketStats(),
                'account_status'  => $this->getAccountStatus(),
                'hotspot_status'  => $this->getHotspotStatus(),
                'sms_stats'       => $this->getSmsStats($today),

                'overdue_invoices' => [
                    'count'  => $this->safe(fn() => Invoice::where('status', 'overdue')->count(), 0),
                    'amount' => $this->safe(fn() => Invoice::where('status', 'overdue')->sum('amount'), 0),
                ],

                'routers' => [
                    'total'   => $this->safe(fn() => Router::count(), 0),
                    'online'  => $this->safe(fn() => Router::where('status', 'online')->count(), 0),
                    'offline' => $this->safe(fn() => Router::where('status', 'offline')->count(), 0),
                ],

                'plan_distribution' => $this->getPlanDistribution(),

                'account_summary' => [
                    'online'    => $this->safe(fn() => ClientAccount::where('status', 'active')->count(), 0),
                    'offline'   => $this->safe(fn() => ClientAccount::where('status', 'inactive')->count(), 0),
                    'overdue'   => $this->safe(fn() => ClientAccount::where('status', 'overdue')->count(), 0),
                    'suspended' => $this->safe(fn() => ClientAccount::where('status', 'suspended')->count(), 0),
                ],
            ];
        });
    }

    /**
     * Get comprehensive analytics for the tenant
     */
    public function getAnalytics(): array
    {
        return [
            'revenue' => $this->getRevenueAnalytics(),
            'clients' => $this->getClientAnalytics(),
            'invoices' => $this->getInvoiceAnalytics(),
            'payments' => $this->getPaymentAnalytics(),
        ];
    }

    /**
     * Revenue analytics with trends
     */
    private function getRevenueAnalytics(): array
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();
        $twoMonthsAgo = now()->subMonths(2)->startOfMonth();

        $thisMonthRevenue = Payment::where('status', 'completed')
            ->where('created_at', '>=', $thisMonth)
            ->sum('amount');

        $lastMonthRevenue = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$lastMonth, $thisMonth->subSecond()])
            ->sum('amount');

        $twoMonthsAgoRevenue = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$twoMonthsAgo, $lastMonth->subSecond()])
            ->sum('amount');

        $lastMonthGrowth = $lastMonthRevenue > 0
            ? (($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100
            : 0;

        return [
            'this_month' => (float) $thisMonthRevenue,
            'last_month' => (float) $lastMonthRevenue,
            'two_months_ago' => (float) $twoMonthsAgoRevenue,
            'growth_percentage' => round($lastMonthGrowth, 2),
        ];
    }

    /**
     * Client analytics
     */
    private function getClientAnalytics(): array
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        $newClientsThisMonth = Client::where('created_at', '>=', $thisMonth)->count();
        $newClientsLastMonth = Client::whereBetween('created_at', [$lastMonth, $thisMonth->subSecond()])->count();

        return [
            'total' => Client::count(),
            'active' => Client::where('status', 'active')->count(),
            'new_this_month' => $newClientsThisMonth,
            'new_last_month' => $newClientsLastMonth,
            'growth_percentage' => $newClientsLastMonth > 0
                ? round((($newClientsThisMonth - $newClientsLastMonth) / $newClientsLastMonth) * 100, 2)
                : 0,
        ];
    }

    /**
     * Invoice analytics
     */
    private function getInvoiceAnalytics(): array
    {
        $thisMonth = now()->startOfMonth();

        $totalInvoiced = Invoice::where('created_at', '>=', $thisMonth)->sum('total');
        $totalCollected = Invoice::where('created_at', '>=', $thisMonth)
            ->where('status', 'paid')
            ->sum('total');
        $outstanding = Invoice::whereIn('status', ['pending', 'overdue'])->sum('total');

        return [
            'this_month_invoiced' => (float) $totalInvoiced,
            'this_month_collected' => (float) $totalCollected,
            'outstanding' => (float) $outstanding,
            'collection_rate' => $totalInvoiced > 0
                ? round(($totalCollected / $totalInvoiced) * 100, 2)
                : 0,
        ];
    }

    /**
     * Payment analytics
     */
    private function getPaymentAnalytics(): array
    {
        $byMethod = Payment::where('status', 'completed')
            ->selectRaw("method, SUM(amount) as total, COUNT(*) as count")
            ->groupBy('method')
            ->get()
            ->map(fn($p) => ['method' => $p->method, 'total' => (float) $p->total, 'count' => $p->count])
            ->toArray();

        return [
            'by_method' => $byMethod,
            'total_transactions' => Payment::where('status', 'completed')->count(),
        ];
    }

    /**
     * Get expenditure summary
     */
    public function getExpenditureSummary(): array
    {
        $thisMonth = now()->startOfMonth();

        return [
            'this_month' => (float) Expenditure::where('date', '>=', $thisMonth)->sum('amount'),
            'by_category' => Expenditure::where('date', '>=', $thisMonth)
                ->selectRaw('category, SUM(amount) as total')
                ->groupBy('category')
                ->pluck('total', 'category')
                ->toArray(),
        ];
    }

    /**
     * Get invoice aging report
     */
    public function getInvoiceAging(): array
    {
        $now = now();

        $current = Invoice::whereIn('status', ['pending', 'overdue'])->where('created_at', '>=', $now->copy()->subDays(30))->sum('total');
        $days30 = Invoice::whereIn('status', ['pending', 'overdue'])->whereBetween('created_at', [$now->copy()->subDays(60), $now->copy()->subDays(31)])->sum('total');
        $days60 = Invoice::whereIn('status', ['pending', 'overdue'])->whereBetween('created_at', [$now->copy()->subDays(90), $now->copy()->subDays(61)])->sum('total');
        $days90 = Invoice::whereIn('status', ['pending', 'overdue'])->where('created_at', '<=', $now->copy()->subDays(91))->sum('total');

        return [
            'current' => (float) $current,
            'days_30' => (float) $days30,
            'days_60' => (float) $days60,
            'days_90' => (float) $days90,
            'total_outstanding' => (float) ($current + $days30 + $days60 + $days90),
        ];
    }

    /**
     * Get churn analysis
     */
    public function getChurnAnalysis(): array
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        $suspendedThisMonth = Client::where('status', 'suspended')
            ->where('updated_at', '>=', $thisMonth)
            ->count();

        $suspendedLastMonth = Client::where('status', 'suspended')
            ->whereBetween('updated_at', [$lastMonth, $thisMonth->subSecond()])
            ->count();

        $totalClients = Client::count();

        return [
            'suspended_this_month' => $suspendedThisMonth,
            'suspended_last_month' => $suspendedLastMonth,
            'churn_rate' => $totalClients > 0 ? round(($suspendedThisMonth / $totalClients) * 100, 2) : 0,
        ];
    }

    /**
     * Invalidate the cached dashboard stats for the current tenant.
     * Call this after any payment, invoice, client, or account mutation.
     */
    public static function invalidateStats(): void
    {
        $tenantId = Tenant::current()?->id ?? 'global';
        Cache::forget("dashboard:stats:{$tenantId}");
    }

    private function getIncomeToday(string $today): array
    {
        return [
            'amount' => $this->safe(fn() => Payment::whereDate('created_at', $today)->where('status', 'completed')->sum('amount'), 0),
            'count'  => $this->safe(fn() => Payment::whereDate('created_at', $today)->where('status', 'completed')->count(), 0),
        ];
    }

    private function getIncomeThisMonth(): array
    {
        return [
            'amount' => $this->safe(fn() => Payment::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->where('status', 'completed')->sum('amount'), 0),
            'count'  => $this->safe(fn() => Payment::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->where('status', 'completed')->count(), 0),
        ];
    }

    private function getActiveUsers(): int
    {
        if (!Schema::hasTable('radius_sessions')) return 0;
        try {
            return \App\Models\RadiusSession::where('status', 'active')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getTicketStats(): array
    {
        return [
            'open'    => $this->safe(fn() => Ticket::where('status', 'open')->count(), 0),
            'pending' => $this->safe(fn() => Ticket::where('status', 'pending')->count(), 0),
            'solved'  => $this->safe(fn() => Ticket::where('status', 'solved')->count(), 0),
            'total'   => $this->safe(fn() => Ticket::count(), 0),
        ];
    }

    private function getAccountStatus(): array
    {
        $activeUsers  = $this->getActiveUsers();
        $totalClients = $this->safe(fn() => Client::count(), 0);

        return [
            'online'    => $activeUsers,
            'offline'   => max(0, $totalClients - $activeUsers),
            'overdue'   => $this->safe(fn() => Invoice::where('status', 'overdue')->distinct('client_id')->count('client_id'), 0),
            'suspended' => $this->safe(fn() => ClientAccount::where('status', 'suspended')->count(), 0),
        ];
    }

    private function getHotspotStatus(): array
    {
        return [
            'online'  => $this->getActiveUsers(),
            'offline' => 0,
            'total'   => $this->safe(fn() => Client::whereHas('accounts', fn($q) => $q->where('type', 'prepaid'))->count(), 0),
        ];
    }

    private function getSmsStats(string $today): array
    {
        if (!Schema::hasTable('sms_logs')) {
            return ['sent_today' => 0, 'failed' => 0];
        }
        return [
            'sent_today' => $this->safe(fn() => SmsLog::whereDate('created_at', $today)->where('status', 'sent')->count(), 0),
            'failed'     => $this->safe(fn() => SmsLog::whereDate('created_at', $today)->where('status', 'failed')->count(), 0),
        ];
    }

    public function getTrafficData(string $period = 'day'): array
    {
        if (!Schema::hasTable('network_traffic')) return [];

        try {
            $routers = Router::where('status', 'online')->get();
            $data    = [];

            foreach ($routers as $router) {
                $query = \App\Models\NetworkTraffic::where('router_id', $router->id);

                match ($period) {
                    'day'   => $query->where('recorded_at', '>=', now()->subDay()),
                    'week'  => $query->where('recorded_at', '>=', now()->subWeek()),
                    'month' => $query->where('recorded_at', '>=', now()->subMonth()),
                    default => $query->where('recorded_at', '>=', now()->subDay()),
                };

                $traffic = $query->orderBy('recorded_at', 'asc')->get();

                $data[] = [
                    'router'  => $router->name,
                    'traffic' => $traffic->map(fn($t) => [
                        'time'    => $t->recorded_at,
                        'tx_mbps' => round($t->tx_bytes / 1048576, 2),
                        'rx_mbps' => round($t->rx_bytes / 1048576, 2),
                    ]),
                ];
            }

            return $data;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getTopDownloaders(int $limit = 10): array
    {
        if (!Schema::hasTable('radius_sessions')) return [];

        try {
            return \App\Models\RadiusSession::with('account.client')
                ->where('status', 'active')
                ->orderBy('bytes_out', 'desc')
                ->limit($limit)
                ->get()
                ->map(fn($s) => [
                    'username'   => $s->username,
                    'client'     => trim(($s->account?->client?->first_name ?? '') . ' ' . ($s->account?->client?->last_name ?? '')),
                    'downloaded' => round($s->bytes_out / 1073741824, 2) . ' GB',
                    'uploaded'   => round($s->bytes_in / 1073741824, 2) . ' GB',
                ])
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

public function getIncomeAnalytics(string $from, string $to, string $groupBy = 'day'): array
    {
        return $this->safe(function () use ($from, $to, $groupBy) {
            // Use SQL aggregation instead of loading all payments into PHP memory.
            // Use TO_CHAR for PostgreSQL compatibility
            $raw = match ($groupBy) {
                'month' => "TO_CHAR(created_at, 'YYYY-MM')",
                'year'  => "TO_CHAR(created_at, 'YYYY')",
                default => "TO_CHAR(created_at, 'YYYY-MM-DD')",
            };

            $rows = Payment::whereBetween('created_at', [$from, $to])
                           ->where('status', 'completed')
                           ->selectRaw("{$raw} as period")
                           ->selectRaw('SUM(amount) as total')
                           ->selectRaw('COUNT(*) as count')
                           ->selectRaw("SUM(CASE WHEN method = 'mpesa' THEN amount ELSE 0 END) as mpesa")
                           ->selectRaw("SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) as cash")
                           ->groupByRaw($raw)
                           ->orderByRaw($raw)
                           ->get();

            return $rows->map(fn($row) => [
                'date'  => $row->period,
                'total' => (float) $row->total,
                'count' => (int) $row->count,
                'mpesa' => (float) $row->mpesa,
                'cash'  => (float) $row->cash,
            ])->toArray();
        }, []);
    }

    private function getPlanDistribution(): array
    {
        return $this->safe(function () {
            return Plan::selectRaw('plans.name, COUNT(client_accounts.id) as count')
                ->leftJoin('client_accounts', 'plans.id', '=', 'client_accounts.plan_id')
                ->groupBy('plans.id', 'plans.name')
                ->orderByDesc('count')
                ->limit(6)
                ->get()
                ->map(fn($p) => [
                    'name'  => $p->name,
                    'count' => (int) $p->count,
                ])
                ->toArray();
        }, []);
    }

    private function safe(callable $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
