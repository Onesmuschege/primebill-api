<?php

namespace App\Services\Dashboard;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\Router;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    public function getStats(): array
    {
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

            'account_summary' => [
                'online'    => $this->safe(fn() => ClientAccount::where('status', 'active')->count(), 0),
                'offline'   => $this->safe(fn() => ClientAccount::where('status', 'inactive')->count(), 0),
                'overdue'   => $this->safe(fn() => ClientAccount::where('status', 'overdue')->count(), 0),
                'suspended' => $this->safe(fn() => ClientAccount::where('status', 'suspended')->count(), 0),
            ],
        ];
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
            $payments = Payment::whereBetween('created_at', [$from, $to])
                               ->where('status', 'completed')
                               ->get();

            $grouped = match ($groupBy) {
                'month' => $payments->groupBy(fn($p) => $p->created_at->format('Y-m')),
                'year'  => $payments->groupBy(fn($p) => $p->created_at->format('Y')),
                default => $payments->groupBy(fn($p) => $p->created_at->format('Y-m-d')),
            };

            return $grouped->map(fn($group, $key) => [
                'date'  => $key,
                'total' => $group->sum('amount'),
                'count' => $group->count(),
                'mpesa' => $group->where('method', 'mpesa')->sum('amount'),
                'cash'  => $group->where('method', 'cash')->sum('amount'),
            ])->values()->toArray();
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