<?php

namespace App\Services\Platform;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\SystemLog;
use App\Models\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PlatformAdminService
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Get comprehensive platform statistics
     */
    public function getStats(): array
    {
        return Cache::remember('platform:stats', self::CACHE_TTL, function () {
            return [
                'overview' => $this->getOverviewStats(),
                'tenants' => $this->getTenantStats(),
                'revenue' => $this->getRevenueStats(),
                'clients' => $this->getClientStats(),
                'infrastructure' => $this->getInfrastructureStats(),
                'security' => $this->getSecurityStats(),
                'activity' => $this->getRecentActivity(),
            ];
        });
    }

    /**
     * Platform overview - key KPIs at a glance
     */
    public function getOverviewStats(): array
    {
        $tenantCounts = Tenant::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalClients = Client::withoutTenantScope()->count();
        $totalRevenue = Payment::withoutTenantScope()
            ->where('status', 'completed')
            ->sum('amount');

        $outstandingInvoices = Invoice::withoutTenantScope()
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('total');

        $totalPayments = Payment::withoutTenantScope()
            ->where('status', 'completed')
            ->count();

        // Calculate MRR (assuming monthly subscriptions)
        $mrr = Payment::withoutTenantScope()
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        return [
            'total_tenants' => Tenant::count(),
            'active_tenants' => (int) ($tenantCounts['active'] ?? 0),
            'trial_tenants' => (int) ($tenantCounts['trial'] ?? 0),
            'suspended_tenants' => (int) ($tenantCounts['suspended'] ?? 0),
            'total_clients' => $totalClients,
            'total_revenue' => (float) $totalRevenue,
            'mrr' => (float) $mrr,
            'arr' => (float) $mrr * 12,
            'outstanding_invoices' => (float) $outstandingInvoices,
            'total_payments' => $totalPayments,
            'avg_revenue_per_tenant' => $totalClients > 0 ? (float) ($totalRevenue / Tenant::count()) : 0,
        ];
    }

    /**
     * Detailed tenant statistics
     */
    public function getTenantStats(): array
    {
        $tenants = Tenant::all();

        $planDistribution = Tenant::selectRaw('plan, count(*) as count')
            ->groupBy('plan')
            ->pluck('count', 'plan')
            ->toArray();

        $newTenantsThisMonth = Tenant::where('created_at', '>=', now()->startOfMonth())->count();
        $newTenantsLastMonth = Tenant::whereBetween('created_at', [
            now()->subMonth()->startOfMonth(),
            now()->subMonth()->endOfMonth(),
        ])->count();

        $growthRate = $newTenantsLastMonth > 0
            ? (($newTenantsThisMonth - $newTenantsLastMonth) / $newTenantsLastMonth) * 100
            : 0;

        return [
            'by_status' => [
                'active' => (int) ($tenants->where('status', 'active')->count()),
                'trial' => (int) ($tenants->where('status', 'trial')->count()),
                'suspended' => (int) ($tenants->where('status', 'suspended')->count()),
            ],
            'by_plan' => $planDistribution,
            'new_this_month' => $newTenantsThisMonth,
            'growth_rate' => round($growthRate, 2),
            'avg_clients_per_tenant' => round(Tenant::count() > 0 ? $tenants->sum('client_count') / Tenant::count() : 0, 1),
        ];
    }

    /**
     * Revenue analytics
     */
    public function getRevenueStats(): array
    {
        $today = now()->toDateString();
        $thisMonth = now()->startOfMonth();
        $thisYear = now()->startOfYear();

        $driver = DB::connection()->getDriverName();

        // Daily revenue for the past 30 days
        $dailyRevenue = Payment::withoutTenantScope()
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw($driver === 'pgsql' ? "TO_CHAR(created_at, 'YYYY-MM-DD') as date" : "strftime('%Y-%m-%d', created_at) as date")
            ->selectRaw('SUM(amount) as total')
            ->groupByRaw($driver === 'pgsql' ? "TO_CHAR(created_at, 'YYYY-MM-DD')" : "strftime('%Y-%m-%d', created_at)")
            ->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => (float) $r->total])
            ->toArray();

        // Monthly revenue for the past 12 months
        $monthlyRevenue = Payment::withoutTenantScope()
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(12))
            ->selectRaw($driver === 'pgsql' ? "TO_CHAR(created_at, 'YYYY-MM') as month" : "strftime('%Y-%m', created_at) as month")
            ->selectRaw('SUM(amount) as total')
            ->groupByRaw($driver === 'pgsql' ? "TO_CHAR(created_at, 'YYYY-MM')" : "strftime('%Y-%m', created_at)")
            ->orderBy('month')
            ->get()
            ->map(fn($r) => ['month' => $r->month, 'total' => (float) $r->total])
            ->toArray();

        // Revenue by payment method
        $byMethod = Payment::withoutTenantScope()
            ->where('status', 'completed')
            ->selectRaw("method, SUM(amount) as total")
            ->groupBy('method')
            ->pluck('total', 'method')
            ->toArray();

        return [
            'today' => (float) Payment::withoutTenantScope()
                ->where('status', 'completed')
                ->whereDate('created_at', $today)
                ->sum('amount'),
            'this_month' => (float) Payment::withoutTenantScope()
                ->where('status', 'completed')
                ->where('created_at', '>=', $thisMonth)
                ->sum('amount'),
            'this_year' => (float) Payment::withoutTenantScope()
                ->where('status', 'completed')
                ->where('created_at', '>=', $thisYear)
                ->sum('amount'),
            'daily' => $dailyRevenue,
            'monthly' => $monthlyRevenue,
            'by_method' => array_map('floatval', $byMethod),
        ];
    }

    /**
     * Client statistics across all tenants
     */
    public function getClientStats(): array
    {
        $newThisMonth = Client::withoutTenantScope()
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $newLastMonth = Client::withoutTenantScope()
            ->whereBetween('created_at', [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
            ])
            ->count();

        $activeClients = Client::withoutTenantScope()
            ->where('status', 'active')
            ->count();

        $suspendedClients = Client::withoutTenantScope()
            ->where('status', 'suspended')
            ->count();

        return [
            'total' => Client::withoutTenantScope()->count(),
            'new_this_month' => $newThisMonth,
            'new_last_month' => $newLastMonth,
            'growth_rate' => $newLastMonth > 0 ? round((($newThisMonth - $newLastMonth) / $newLastMonth) * 100, 2) : 0,
            'by_status' => [
                'active' => $activeClients,
                'suspended' => $suspendedClients,
                'inactive' => Client::withoutTenantScope()->where('status', 'inactive')->count(),
            ],
        ];
    }

    /**
     * Infrastructure health metrics
     */
    public function getInfrastructureStats(): array
    {
        // Router stats from all tenants
        $routers = DB::table('routers')
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Estimate based on typical deployment
        $onlineRouters = (int) ($routers['online'] ?? 0);
        $offlineRouters = (int) ($routers['offline'] ?? 0);

        return [
            'routers' => [
                'total' => $onlineRouters + $offlineRouters,
                'online' => $onlineRouters,
                'offline' => $offlineRouters,
                'health_percentage' => ($onlineRouters + $offlineRouters) > 0
                    ? round(($onlineRouters / ($onlineRouters + $offlineRouters)) * 100, 1)
                    : 100,
            ],
            'cache' => [
                'driver' => config('cache.default'),
                'status' => 'healthy', // Would check actual cache health in production
            ],
            'queue' => [
                'default' => config('queue.default'),
                'status' => 'running',
            ],
            'database' => [
                'driver' => config('database.default'),
                'status' => 'connected',
            ],
        ];
    }

    /**
     * Security metrics
     */
    public function getSecurityStats(): array
    {
        $today = now()->toDateString();
        $thisWeek = now()->subDays(7);

        // Failed login attempts
        $failedLogins = SystemLog::where('action', 'like', 'auth.login.failed%')
            ->whereDate('created_at', $today)
            ->count();

        $failedLoginsThisWeek = SystemLog::where('action', 'like', 'auth.login.failed%')
            ->where('created_at', '>=', $thisWeek)
            ->count();

        // Successful logins
        $successfulLogins = SystemLog::where('action', 'auth.login.success')
            ->whereDate('created_at', $today)
            ->count();

        $successfulLoginsThisWeek = SystemLog::where('action', 'auth.login.success')
            ->where('created_at', '>=', $thisWeek)
            ->count();

        // Security events
        $securityEvents = SystemLog::where('action', 'like', 'security.%')
            ->where('created_at', '>=', $thisWeek)
            ->count();

        // Platform admin users
        $platformAdmins = User::where('is_platform_admin', true)->count();

        return [
            'failed_logins_today' => $failedLogins,
            'failed_logins_this_week' => $failedLoginsThisWeek,
            'successful_logins_today' => $successfulLogins,
            'successful_logins_this_week' => $successfulLoginsThisWeek,
            'security_events_this_week' => $securityEvents,
            'platform_admins' => $platformAdmins,
            'suspicious_ips' => $this->getSuspiciousIPs(),
        ];
    }

    /**
     * Get IPs with multiple failed login attempts
     */
    private function getSuspiciousIPs(): array
    {
        return SystemLog::where('action', 'like', 'auth.login.failed%')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('ip_address, count(*) as attempts')
            ->groupBy('ip_address')
            ->having('attempts', '>', 5)
            ->orderByDesc('attempts')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'ip' => $r->ip_address,
                'attempts' => $r->attempts,
            ])
            ->toArray();
    }

    /**
     * Recent platform activity
     */
    public function getRecentActivity(): array
    {
        return SystemLog::with('user')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'model' => $log->model,
                'model_id' => $log->model_id,
                'user' => $log->user?->name ?? 'System',
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at->toISOString(),
            ])
            ->toArray();
    }

    /**
     * Get tenant list with detailed metrics
     */
    public function getTenants(?string $status = null, ?string $search = null): array
    {
        $query = Tenant::query()->orderBy('name');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        return $query->get()
            ->map(function (Tenant $tenant) {
                $clientCount = Client::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->count();

                $revenue = Payment::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'completed')
                    ->sum('amount');

                $outstanding = Invoice::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('status', ['pending', 'overdue'])
                    ->sum('total');

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status,
                    'plan' => $tenant->plan,
                    'billing_cycle' => $tenant->billing_cycle,
                    'currency' => $tenant->currency,
                    'timezone' => $tenant->timezone,
                    'contact_email' => $tenant->contact_email,
                    'client_count' => $clientCount,
                    'max_clients' => $tenant->max_clients,
                    'revenue' => (float) $revenue,
                    'outstanding_invoices' => (float) $outstanding,
                    'created_at' => $tenant->created_at->toISOString(),
                    'plan_expires_at' => $tenant->plan_expires_at?->toISOString(),
                    'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
                ];
            })
            ->toArray();
    }

    /**
     * Get detailed tenant information
     */
    public function getTenantDetail(Tenant $tenant): array
    {
        $clientCount = Client::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->count();

        $revenue = Payment::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->sum('amount');

        $outstanding = Invoice::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['pending', 'overdue'])
            ->sum('total');

        $recentPayments = Payment::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($p) => [
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'created_at' => $p->created_at->toISOString(),
            ])
            ->toArray();

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'plan' => $tenant->plan,
            'billing_cycle' => $tenant->billing_cycle,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone,

            // Company Details
            'contact_email' => $tenant->contact_email,
            'contact_phone' => $tenant->contact_phone,
            'address' => $tenant->address,
            'website' => $tenant->website,

            // Branding
            'primary_color' => $tenant->primary_color,
            'secondary_color' => $tenant->secondary_color,
            'custom_domain' => $tenant->custom_domain,

            // Subscription
            'plan_started_at' => $tenant->plan_started_at?->toISOString(),
            'plan_expires_at' => $tenant->plan_expires_at?->toISOString(),
            'monthly_price' => (float) $tenant->monthly_price,
            'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),

            // Quotas
            'max_clients' => $tenant->max_clients,
            'max_users' => $tenant->max_users,
            'max_routers' => $tenant->max_routers,
            'storage_quota_gb' => $tenant->storage_quota_gb,
            'api_calls_per_month' => $tenant->api_calls_per_month,

            // Usage
            'api_calls_used' => $tenant->api_calls_used,
            'storage_used_mb' => $tenant->storage_used_mb,

            // Billing
            'billing_email' => $tenant->billing_email,
            'billing_contact_name' => $tenant->billing_contact_name,
            'tax_name' => $tenant->tax_name,
            'tax_number' => $tenant->tax_number,
            'tax_rate' => (float) $tenant->tax_rate,

            // Metrics
            'client_count' => $clientCount,
            'revenue' => (float) $revenue,
            'outstanding_invoices' => (float) $outstanding,
            'created_at' => $tenant->created_at->toISOString(),
            'recent_payments' => $recentPayments,
        ];
    }

    /**
     * Invalidate platform stats cache
     */
    public static function invalidateCache(): void
    {
        Cache::forget('platform:stats');
    }
}
