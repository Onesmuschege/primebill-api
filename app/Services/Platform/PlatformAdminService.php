<?php

namespace App\Services\Platform;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformInvoice;
use App\Models\Tenant;
use App\Models\TenantSubscription;
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
        // v2 cache key: invalidates the pre-F1 payload where "mrr" was derived
        // from tenant-CLIENT payments (wrong semantics — see getOverviewStats).
        return Cache::remember('platform:stats:v2', self::CACHE_TTL, function () {
            return [
                'overview' => $this->getOverviewStats(),
                'tenants' => $this->getTenantStats(),
                'revenue' => $this->getRevenueStats(),
                'clients' => $this->getClientStats(),
                'infrastructure' => $this->getInfrastructureStats(),
                'security' => $this->getSecurityStats(),
                'activity' => $this->getRecentActivity(),
                'billing' => $this->getBillingStats(),
                'ops_queues' => $this->getOpsQueues(),
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

        // ── Platform MRR/ARR (F1 fix) ────────────────────────────────────────
        // MRR is PrimeBill's RECURRING REVENUE FROM ITS TENANTS, derived from
        // TenantSubscription — NOT from tenant-client payments. Tenant-client
        // payment volume is the ISPs' own business activity, not PrimeBill
        // revenue. Annual subscriptions are amortized at 1/12 per month so
        // yearly plans contribute to MRR too. This matches (and now agrees
        // with) PlatformSubscriptionController::stats.
        $monthlyMrr = (float) TenantSubscription::where('status', 'active')
            ->where('billing_cycle', 'monthly')
            ->sum('price');
        $annualAmortized = (float) TenantSubscription::where('status', 'active')
            ->where('billing_cycle', 'annual')
            ->sum('price') / 12;
        $mrr = $monthlyMrr + $annualAmortized;

        // Revenue bridge (§16): what subscription revenue entered / left this
        // month. Real data from starts_at / cancelled_at / suspended_at — no
        // fabricated "previous month" number (no subscription price history
        // table exists yet, so an exact MoM MRR delta is a documented gap).
        $mrrNewThisMonth = TenantSubscription::where('status', 'active')
            ->where('starts_at', '>=', now()->startOfMonth())
            ->get()
            ->sum(fn (TenantSubscription $s) => $s->billing_cycle === 'annual' ? (float) $s->price / 12 : (float) $s->price);

        $mrrChurnedThisMonth = TenantSubscription::where(function ($q) {
            $q->where('status', 'cancelled')->whereNotNull('cancelled_at')
                ->where('cancelled_at', '>=', now()->startOfMonth());
            $q->orWhere('status', 'suspended')->whereNotNull('suspended_at')
                ->where('suspended_at', '>=', now()->startOfMonth());
        })
            ->get()
            ->sum(fn (TenantSubscription $s) => $s->billing_cycle === 'annual' ? (float) $s->price / 12 : (float) $s->price);

        return [
            'total_tenants' => Tenant::count(),
            'active_tenants' => (int) ($tenantCounts['active'] ?? 0),
            'trial_tenants' => (int) ($tenantCounts['trial'] ?? 0),
            'suspended_tenants' => (int) ($tenantCounts['suspended'] ?? 0),
            'total_clients' => $totalClients,
            // Client payment volume across all tenants — the ISPs' own billing
            // activity, NOT PrimeBill revenue. Kept for operational context.
            'total_revenue' => (float) $totalRevenue,
            'mrr' => (float) $mrr,
            'arr' => (float) $mrr * 12,
            'mrr_new_this_month' => (float) $mrrNewThisMonth,
            'mrr_churned_this_month' => (float) $mrrChurnedThisMonth,
            'outstanding_invoices' => (float) $outstandingInvoices,
            'total_payments' => $totalPayments,
            'avg_revenue_per_tenant' => $totalClients > 0 ? (float) ($totalRevenue / Tenant::count()) : 0,
        ];
    }

    /**
     * PrimeBill's own commercial position with its tenants — derived from
     * PlatformInvoice (what PrimeBill bills ISPs for their subscriptions).
     * Deliberately separate from the client Payment/Invoice volume above.
     */
    public function getBillingStats(): array
    {
        return [
            'outstanding_total' => (float) PlatformInvoice::whereIn('status', ['draft', 'sent', 'overdue'])
                ->sum('total'),
            'outstanding_overdue_total' => (float) PlatformInvoice::where('status', 'overdue')
                ->sum('total'),
            'overdue_count' => PlatformInvoice::where('status', 'overdue')->count(),
            'paid_this_month' => (float) PlatformInvoice::where('status', 'paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('total'),
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
     * Infrastructure health metrics.
     *
     * Phase 6 (Observability): health signals are now REAL measurements, not
     * hardcoded claims. Database and cache reachability are probed at request
     * time; average response time is a measured DB round-trip; router fleet
     * health comes from real router rows. The only non-headless signal is the
     * queue worker — sync driver is verifiably in-request, but a queued driver
     * cannot report a heartbeat without a worker/ping table (documented gap,
     * surfaced honestly as `unverified` rather than pretending it is up).
     */
    public function getInfrastructureStats(): array
    {
        // Router stats from all tenants (real fleet data).
        $routers = DB::table('routers')
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $onlineRouters = (int) ($routers['online'] ?? 0);
        $offlineRouters = (int) ($routers['offline'] ?? 0);
        $routerTotal = $onlineRouters + $offlineRouters;

        // Real reachability probes.
        $databaseUp = $this->databaseIsReachable();
        $cacheUp = $this->cacheIsReachable();

        // Router fleet status — 0 registered routers is `unverified`, not "up".
        $routerStatus = $routerTotal === 0
            ? 'unverified'
            : ($offlineRouters === 0 ? 'up' : ($onlineRouters === 0 ? 'down' : 'degraded'));

        // Queue worker: sync executes in-request (verifiably up); a queued
        // driver needs a worker heartbeat we don't instrument yet.
        $queueStatus = config('queue.default') === 'sync' ? 'up' : 'unverified';

        return [
            'status' => $databaseUp && $cacheUp ? 'operational' : 'degraded',
            'avg_response_time' => $this->measureDatabaseLatencyMs(),
            'response_time_unit' => 'ms',
            'services' => [
                [
                    'name' => 'Database',
                    'status' => $databaseUp ? 'up' : 'down',
                    'detail' => config('database.default'),
                ],
                [
                    'name' => 'Cache',
                    'status' => $cacheUp ? 'up' : 'down',
                    'detail' => config('cache.default'),
                ],
                [
                    'name' => 'Queue worker',
                    'status' => $queueStatus,
                    'detail' => config('queue.default') === 'sync'
                        ? 'sync (in-request)'
                        : 'driver: '.config('queue.default').' · worker heartbeat not instrumented (gap)',
                ],
                [
                    'name' => 'Router fleet',
                    'status' => $routerStatus,
                    'detail' => $routerTotal > 0
                        ? "{$onlineRouters} online / {$offlineRouters} offline"
                        : 'no routers registered across tenants',
                ],
            ],
            'routers' => [
                'total' => $routerTotal,
                'online' => $onlineRouters,
                'offline' => $offlineRouters,
                'health_percentage' => $routerTotal > 0
                    ? round(($onlineRouters / $routerTotal) * 100, 1)
                    : 100,
            ],
            'cache' => [
                'driver' => config('cache.default'),
                'status' => $cacheUp ? 'healthy' : 'down',
            ],
            'queue' => [
                'default' => config('queue.default'),
                'status' => $queueStatus === 'up' ? 'running' : 'unverified',
            ],
            'database' => [
                'driver' => config('database.default'),
                'status' => $databaseUp ? 'connected' : 'disconnected',
            ],
        ];
    }

    /**
     * True when the primary database connection answers a probe query.
     */
    private function databaseIsReachable(): bool
    {
        try {
            return DB::connection()->getPdo() instanceof \PDO;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when the default cache driver answers a read without throwing.
     */
    private function cacheIsReachable(): bool
    {
        try {
            Cache::has('platform:health:probe');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Measured round-trip latency of a real DB probe (milliseconds).
     * Returns 0.0 when the database is unreachable, never a fabricated value.
     */
    private function measureDatabaseLatencyMs(): float
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $ms = (microtime(true) - $start) * 1000;

            return round(max($ms, 0.1), 1);
        } catch (\Throwable) {
            return 0.0;
        }
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
            ->havingRaw('count(*) > ?', [5])
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
     * Operational attention queues for the platform command center (§8 Layer 6).
     *
     * Only REAL, backend-derived conditions appear here. Queues the platform
     * cannot yet measure across tenants (failed integrations, incidents) are
     * marked `available: false` rather than fabricating a count — the frontend
     * shows an honest "backend gap" empty state for those.
     */
    public function getOpsQueues(): array
    {
        // Expiring trials — tenants still on 'trial' whose trial window ends
        // within 7 days (or already passed without conversion).
        $expiringTrials = Tenant::where('status', 'trial')
            ->where('trial_ends_at', '<=', now()->addDays(7))
            ->orderBy('trial_ends_at')
            ->limit(5)
            ->get()
            ->map(fn (Tenant $t) => [
                'tenant_id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'days_left' => $t->trial_ends_at ? now()->diffInDays($t->trial_ends_at, false) : null,
            ])
            ->values()
            ->toArray();

        // Overdue PrimeBill invoices, grouped by tenant (who owes PrimeBill).
        $overdueAccounts = DB::table('platform_invoices as pi')
            ->join('tenants as t', 't.id', '=', 'pi.tenant_id')
            ->where('pi.status', 'overdue')
            ->groupBy('t.id', 't.name', 't.slug')
            ->selectRaw('t.id as tenant_id, t.name, t.slug, COUNT(*) as invoice_count, SUM(pi.total) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'tenant_id' => (int) $r->tenant_id,
                'name' => $r->name,
                'slug' => $r->slug,
                'invoice_count' => (int) $r->invoice_count,
                'total' => (float) $r->total,
            ])
            ->values()
            ->toArray();

        // Tenants at or above 90% of any soft quota (clients / API / storage).
        $nearClient = DB::table('tenants as t')
            ->leftJoin('clients as c', 'c.tenant_id', '=', 't.id')
            ->where('t.max_clients', '>', 0)
            ->whereNull('c.deleted_at')
            ->selectRaw('t.id as tenant_id, t.name, t.slug, "clients" as metric, COUNT(c.id) as used, t.max_clients as limit_value')
            ->groupBy('t.id', 't.name', 't.slug', 't.max_clients')
            ->havingRaw('(COUNT(c.id) * 1.0) / t.max_clients >= 0.9')
            ->orderByRaw('(COUNT(c.id) * 1.0) / t.max_clients DESC')
            ->limit(3)
            ->get();

        $nearApi = DB::table('tenants as t')
            ->where('api_calls_per_month', '>', 0)
            ->whereRaw('(api_calls_used * 1.0) / api_calls_per_month >= 0.9')
            ->selectRaw('t.id as tenant_id, t.name, t.slug, "api" as metric, t.api_calls_used as used, t.api_calls_per_month as limit_value')
            ->orderByRaw('(api_calls_used * 1.0) / api_calls_per_month DESC')
            ->limit(3)
            ->get();

        $nearStorage = DB::table('tenants as t')
            ->where('storage_quota_gb', '>', 0)
            ->whereRaw('(storage_used_mb * 1.0) / (storage_quota_gb * 1024) >= 0.9')
            ->selectRaw('t.id as tenant_id, t.name, t.slug, "storage" as metric, t.storage_used_mb as used, t.storage_quota_gb as limit_value')
            ->orderByRaw('(storage_used_mb * 1.0) / (storage_quota_gb * 1024) DESC')
            ->limit(3)
            ->get();

        $nearLimit = collect(array_merge($nearClient->toArray(), $nearApi->toArray(), $nearStorage->toArray()))
            ->map(fn ($r) => [
                'tenant_id' => (int) $r->tenant_id,
                'name' => $r->name,
                'slug' => $r->slug,
                'metric' => $r->metric,
                'used' => (float) $r->used,
                'limit_value' => (float) $r->limit_value,
                'ratio' => round(((float) $r->used / max(1, (float) $r->limit_value)) * 100, 1),
            ])
            ->sortByDesc('ratio')
            ->unique('tenant_id')
            ->take(5)
            ->values()
            ->toArray();

        // Failed background jobs — Laravel's own queue failure ledger.
        try {
            $failedJobsCount = (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failedJobsCount = 0;
        }

        $securityEvents = SystemLog::where('action', 'like', 'security.%')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'tenant_id' => $log->tenant_id,
                'created_at' => $log->created_at->toISOString(),
            ])
            ->values()
            ->toArray();

        return [
            'expiring_trials' => [
                'available' => true,
                'label' => 'Expiring trials',
                'count' => count($expiringTrials),
                'items' => $expiringTrials,
            ],
            'overdue_accounts' => [
                'available' => true,
                'label' => 'Tenants owing PrimeBill',
                'count' => count($overdueAccounts),
                'items' => $overdueAccounts,
            ],
            'near_limit' => [
                'available' => true,
                'label' => 'Tenants near limits',
                'count' => count($nearLimit),
                'items' => $nearLimit,
            ],
            'failed_jobs' => [
                'available' => true,
                'label' => 'Failed jobs',
                'count' => $failedJobsCount,
                'items' => [],
            ],
            'security_events' => [
                'available' => true,
                'label' => 'Security events (7d)',
                'count' => (int) SystemLog::where('action', 'like', 'security.%')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'items' => $securityEvents,
            ],
            // Backend gaps — no platform-wide integration registry or incident
            // feed exists yet (both are tenant-scoped today). Surfaced honestly.
            'failed_integrations' => [
                'available' => false,
                'label' => 'Failed integrations',
                'count' => 0,
                'items' => [],
            ],
            'incidents' => [
                'available' => false,
                'label' => 'Unresolved incidents',
                'count' => 0,
                'items' => [],
            ],
        ];
    }

    /**
     * Get tenant list with detailed metrics (legacy: full array, client-side
     * enrichment of every tenant). Kept for consumers that need the complete
     * enriched list (PlatformSystemHealth, etc.). New code should prefer
     * getTenantsPaginated().
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
            ->map(fn (Tenant $tenant) => $this->enrichTenant($tenant))
            ->toArray();
    }

    /**
     * Get tenant list with server-side pagination, filtering, and sorting.
     *
     * Unlike getTenants() which loads and enriches every tenant, this method
     * filters/sorts at the database level and enriches only the current page's
     * tenants — scaling to thousands of tenants without pulling every tenant's
     * metrics into memory.
     *
     * Return shape matches Laravel's paginate() output so the controller can
     * return it directly:
     *   { data: [...], total, current_page, per_page, last_page, from, to }
     */
    public function getTenantsPaginated(
        ?string $status = null,
        ?string $search = null,
        int $perPage = 20,
        int $page = 1,
        string $sort = 'created_at',
        string $direction = 'desc',
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = Tenant::query()
            ->withCount('clients');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Map sort fields to DB expressions. client_count uses the withCount
        // alias; mrr uses monthly_price as a proxy for subscription revenue.
        $sortable = [
            'name' => 'name',
            'status' => 'status',
            'plan' => 'plan',
            'created_at' => 'created_at',
            'client_count' => 'clients_count',
            'mrr' => 'monthly_price',
        ];

        $sortColumn = $sortable[$sort] ?? 'created_at';
        $direction = in_array($direction, ['asc', 'desc']) ? $direction : 'desc';

        $query->orderBy($sortColumn, $direction);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Enrich only the current page's tenants (not the full collection).
        $paginator->getCollection()->transform(function (Tenant $tenant) {
            return $this->enrichTenant($tenant);
        });

        return $paginator;
    }

    /**
     * Enrich a tenant with computed metrics (client count, revenue, outstanding).
     * Shared by getTenants() and getTenantsPaginated().
     */
    private function enrichTenant(Tenant $tenant): array
    {
        // clients_count is available when the query used withCount('clients');
        // fall back to an explicit count for callers that didn't.
        $clientCount = $tenant->clients_count ?? Client::withoutTenantScope()
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
            'client_count' => (int) $clientCount,
            'max_clients' => $tenant->max_clients,
            'revenue' => (float) $revenue,
            'outstanding_invoices' => (float) $outstanding,
            'created_at' => $tenant->created_at->toISOString(),
            'plan_expires_at' => $tenant->plan_expires_at?->toISOString(),
            'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
        ];
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
