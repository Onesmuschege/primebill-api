<?php

namespace App\Services\Platform;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Router;
use App\Models\SystemLog;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-tenant reporting for the Platform Console — aggregates real stored
 * data across every ISP tenant on PrimeBill (payments, tenants, clients,
 * routers, quota usage), never fabricated metrics.
 *
 * Every aggregate is cached briefly (300s) via the same Cache::remember
 * pattern PlatformAdminService::getStats() already uses. Writes to the
 * underlying tables may leave a stale entry for up to that window — an
 * intentional, documented tradeoff for reads, mirroring getStats().
 */
class PlatformReportService
{
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Revenue over a date range — total, by tenant, by plan, by payment
     * method, plus a daily series for time-series charts.
     */
    public function revenue(string $from, string $to): array
    {
        return Cache::remember("platform:report:revenue:{$from}:{$to}", self::CACHE_TTL, function () use ($from, $to) {
            $toEnd = "{$to} 23:59:59";

            $payments = Payment::withoutTenantScope()
                ->where('status', 'completed')
                ->whereBetween('created_at', [$from, $toEnd])
                ->orderBy('created_at')
                ->get();

            $total = (float) $payments->sum('amount');
            $count = $payments->count();
            $tenantNames = Tenant::pluck('name', 'id')->toArray();
            $tenantPlans = Tenant::pluck('plan', 'id')->toArray();

            // By payment method
            $byMethod = $payments->groupBy('method')
                ->map(fn ($g) => (float) $g->sum('amount'))
                ->toArray();

            // By tenant + by plan, both derived from the same rows
            $byTenant = [];
            $planTotals = [];
            foreach ($payments as $p) {
                $tid = $p->tenant_id;
                $byTenant[$tid] = ($byTenant[$tid] ?? 0) + (float) $p->amount;
                $plan = $tenantPlans[$tid] ?? 'unknown';
                $planTotals[$plan] = ($planTotals[$plan] ?? 0) + (float) $p->amount;
            }
            $byTenantRows = [];
            foreach ($byTenant as $tid => $amount) {
                $byTenantRows[] = [
                    'tenant_id' => $tid,
                    'name' => $tenantNames[$tid] ?? "Tenant #{$tid}",
                    'amount' => (float) $amount,
                ];
            }
            usort($byTenantRows, fn ($a, $b) => $b['amount'] <=> $a['amount']);

            // Daily series from raw rows so the chart is exactly the same
            // data as the totals (no separate query to drift).
            $dailyByDate = [];
            foreach ($payments as $p) {
                $date = $p->created_at->toDateString();
                $dailyByDate[$date] = ($dailyByDate[$date] ?? 0) + (float) $p->amount;
            }
            ksort($dailyByDate);
            $daily = [];
            foreach ($dailyByDate as $date => $amount) {
                $daily[] = ['date' => $date, 'amount' => (float) $amount];
            }

            return [
                'total' => $total,
                'count' => $count,
                'by_method' => array_map('floatval', $byMethod),
                'by_tenant' => $byTenantRows,
                'by_plan' => array_map('floatval', $planTotals),
                'daily' => $daily,
            ];
        });
    }

    /**
     * Tenant growth/churn over a date range — signups, cancellations and
     * plan changes. Signups + cancellations come straight from Tenant rows;
     * plan changes come from the real audit trail (tenant.plan_assigned),
     * the same source the Platform Audit Log page renders.
     */
    public function tenants(string $from, string $to): array
    {
        return Cache::remember("platform:report:tenants:{$from}:{$to}", self::CACHE_TTL, function () use ($from, $to) {
            $toEnd = "{$to} 23:59:59";

            $created = Tenant::whereBetween('created_at', [$from, $toEnd])
                ->orderBy('created_at')
                ->get();

            $signups = $created->count();
            $cancelled = Tenant::whereBetween('archived_at', [$from, $toEnd])->count();
            $suspended = Tenant::whereBetween('suspended_at', [$from, $toEnd])->count();

            // Monthly signup series (from the same Tenant rows — real data).
            $monthly = [];
            foreach ($created as $t) {
                $month = $t->created_at->format('Y-m');
                $monthly[$month] = ($monthly[$month] ?? 0) + 1;
            }
            ksort($monthly);
            $signupsSeries = [];
            foreach ($monthly as $month => $count) {
                $signupsSeries[] = ['month' => $month, 'count' => $count];
            }

            // Plan changes (assignPlan writes tenant.plan_assigned) — this
            // covers upgrades and downgrades from the same event stream.
            $planChanges = SystemLog::where('action', 'like', 'tenant.plan_assigned%')
                ->whereBetween('created_at', [$from, $toEnd])
                ->count();

            return [
                'signups' => $signups,
                'cancelled' => $cancelled,
                'suspended' => $suspended,
                'plan_changes' => $planChanges,
                'signups_series' => $signupsSeries,
                'by_plan' => $created->groupBy('plan')->map(fn ($g) => $g->count())->toArray(),
            ];
        });
    }

    /**
     * Aggregate usage against each tenant's quota. Every number here is a
     * stored column on Tenant (api_calls_used, storage_used_mb) or a real
     * cross-tenant count of Client / Router rows. Nothing is invented.
     */
    public function usage(): array
    {
        return Cache::remember('platform:report:usage', self::CACHE_TTL, function () {
            $tenants = Tenant::query()->orderBy('name')->get();

            $clientCounts = Client::withoutTenantScope()
                ->selectRaw('tenant_id, count(*) as c')
                ->groupBy('tenant_id')
                ->pluck('c', 'tenant_id')
                ->toArray();
            $routerCounts = Router::withoutTenantScope()
                ->selectRaw('tenant_id, count(*) as c')
                ->groupBy('tenant_id')
                ->pluck('c', 'tenant_id')
                ->toArray();

            $rows = [];
            foreach ($tenants as $t) {
                $clients = (int) ($clientCounts[$t->id] ?? 0);
                $routers = (int) ($routerCounts[$t->id] ?? 0);
                $apiPct = $t->api_calls_per_month > 0
                    ? round(($t->api_calls_used / $t->api_calls_per_month) * 100, 2) : 0;
                $storagePct = $t->storage_quota_gb > 0
                    ? round((($t->storage_used_mb ?? 0) / 1024 / $t->storage_quota_gb) * 100, 2) : 0;

                $rows[] = [
                    'tenant_id' => $t->id,
                    'name' => $t->name,
                    'status' => $t->status,
                    'plan' => $t->plan,
                    'max_clients' => $t->max_clients,
                    'clients' => $clients,
                    'clients_pct' => $t->max_clients > 0 ? round(($clients / $t->max_clients) * 100, 2) : 0,
                    'max_routers' => $t->max_routers,
                    'routers' => $routers,
                    'routers_pct' => $t->max_routers > 0 ? round(($routers / $t->max_routers) * 100, 2) : 0,
                    'api_calls_per_month' => $t->api_calls_per_month,
                    'api_calls_used' => $t->api_calls_used,
                    'api_calls_pct' => $apiPct,
                    'storage_quota_gb' => $t->storage_quota_gb,
                    'storage_used_mb' => $t->storage_used_mb,
                    'storage_pct' => $storagePct,
                ];
            }

            return [
                'total' => count($rows),
                'categories' => [
                    'clients' => 'clients_pct',
                    'routers' => 'routers_pct',
                    'api_calls' => 'api_calls_pct',
                    'storage' => 'storage_pct',
                ],
                'rows' => $rows,
            ];
        });
    }

    /**
     * Build a CSV body for the given report type, mirroring the existing
     * tenant-side ReportController::export() flat-total approach but also
     * including per-tenant rows where each type has them (so the export is
     * actually useful, not just a stub).
     */
    public function exportCsv(string $type, string $from, string $to): string
    {
        $data = match ($type) {
            'revenue' => $this->revenue($from, $to),
            'tenants' => $this->tenants($from, $to),
            default => [],
        };

        $lines = ['Key,Value'];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $lines[] = "{$key},{$value}";
        }

        if ($type === 'revenue') {
            $lines[] = '';
            $lines[] = 'Tenant ID,Name,Amount';
            foreach ($data['by_tenant'] ?? [] as $row) {
                $lines[] = "{$row['tenant_id']},".self::csvEscape($row['name']).",{$row['amount']}";
            }
            $lines[] = '';
            $lines[] = 'Date,Amount';
            foreach ($data['daily'] ?? [] as $row) {
                $lines[] = "{$row['date']},{$row['amount']}";
            }
        }

        if ($type === 'tenants') {
            $lines[] = 'Month,Signups';
            foreach ($data['signups_series'] ?? [] as $row) {
                $lines[] = "{$row['month']},{$row['count']}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function csvEscape(string $value): string
    {
        if (strpbrk($value, ",\"\n") !== false) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
