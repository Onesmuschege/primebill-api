<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\TenantSubscription;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;

/**
 * Revenue analytics for the PrimeBill operator — the deepened commercial
 * view behind /platform/analytics. All figures are PrimeBill's own revenue
 * from its tenant ISPs (PlatformInvoice + TenantSubscription), deliberately
 * separate from the tenant-client Payment volume that the tenant-side
 * finance module serves.
 *
 * No data here is fabricated: every series is a real DB aggregation.
 */
class PlatformAnalyticsController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/platform/analytics
     */
    public function index()
    {
        return $this->success([
            'mrr' => $this->mrrBreakdown(),
            'monthly_trend' => $this->monthlyTrend(),
            'by_plan' => $this->revenueByPlan(),
            'by_method' => $this->revenueByMethod(),
            'invoice_status' => $this->invoiceStatusBreakdown(),
        ]);
    }

    /**
     * MRR / ARR with new & churned deltas for the current month.
     */
    protected function mrrBreakdown(): array
    {
        $monthlyMrr = (float) TenantSubscription::where('status', 'active')
            ->where('billing_cycle', 'monthly')
            ->sum('price');
        $annualAmortized = (float) TenantSubscription::where('status', 'active')
            ->where('billing_cycle', 'annual')
            ->sum('price') / 12;
        $mrr = $monthlyMrr + $annualAmortized;

        $newThisMonth = TenantSubscription::where('status', 'active')
            ->where('starts_at', '>=', now()->startOfMonth())
            ->get()
            ->sum(fn (TenantSubscription $s) => $s->billing_cycle === 'annual'
                ? (float) $s->price / 12
                : (float) $s->price);

        $churnedThisMonth = TenantSubscription::where(function ($q) {
            $q->where('status', 'cancelled')->whereNotNull('cancelled_at')
                ->where('cancelled_at', '>=', now()->startOfMonth());
            $q->orWhere('status', 'suspended')->whereNotNull('suspended_at')
                ->where('suspended_at', '>=', now()->startOfMonth());
        })
            ->get()
            ->sum(fn (TenantSubscription $s) => $s->billing_cycle === 'annual'
                ? (float) $s->price / 12
                : (float) $s->price);

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'new_this_month' => round($newThisMonth, 2),
            'churned_this_month' => round($churnedThisMonth, 2),
            'active_count' => TenantSubscription::where('status', 'active')->count(),
            'trial_count' => TenantSubscription::where('status', 'trial')->count(),
        ];
    }

    /**
     * 12-month paid-revenue trend from PlatformInvoice.
     */
    protected function monthlyTrend(): array
    {
        $driver = DB::connection()->getDriverName();
        $raw = PlatformInvoice::where('status', 'paid')
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', now()->subMonths(12)->startOfMonth())
            ->selectRaw(
                $driver === 'pgsql'
                    ? "TO_CHAR(paid_at, 'YYYY-MM') as period"
                    : "strftime('%Y-%m', paid_at) as period"
            )
            ->selectRaw('SUM(total) as total')
            ->groupByRaw(
                $driver === 'pgsql'
                    ? "TO_CHAR(paid_at, 'YYYY-MM')"
                    : "strftime('%Y-%m', paid_at)"
            )
            ->orderBy('period')
            ->get()
            ->map(fn ($r) => ['period' => $r->period, 'total' => (float) $r->total])
            ->toArray();

        // Fill zero-value months for a continuous 12-month axis.
        $filled = [];
        for ($i = 11; $i >= 0; $i--) {
            $period = now()->subMonths($i)->format('Y-m');
            $match = collect($raw)->firstWhere('period', $period);
            $filled[] = ['period' => $period, 'total' => $match ? $match['total'] : 0];
        }

        return $filled;
    }

    /**
     * Revenue + subscriber count per plan.
     */
    protected function revenueByPlan(): array
    {
        $byPlan = DB::table('platform_invoices')
            ->join('tenant_subscriptions', 'platform_invoices.subscription_id', '=', 'tenant_subscriptions.id')
            ->join('subscription_plans', 'tenant_subscriptions.plan_id', '=', 'subscription_plans.id')
            ->where('platform_invoices.status', 'paid')
            ->selectRaw('subscription_plans.id as plan_id')
            ->selectRaw('subscription_plans.name as plan_name')
            ->selectRaw('SUM(platform_invoices.total) as revenue')
            ->selectRaw('COUNT(DISTINCT platform_invoices.tenant_id) as tenant_count')
            ->groupBy('subscription_plans.id', 'subscription_plans.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'plan_id' => (int) $r->plan_id,
                'plan_name' => $r->plan_name,
                'revenue' => (float) $r->revenue,
                'tenant_count' => (int) $r->tenant_count,
            ])
            ->toArray();

        return array_values($byPlan);
    }

    /**
     * Revenue grouped by payment method on PlatformInvoice.
     *
     * Backend gap: PlatformInvoice has no `payment_method` column (only a
     * free-text `reference`). Without a payment-method field this breakdown
     * cannot be computed, so it returns an empty array rather than a
     * fabricated distribution. Documented per §16 gap.
     */
    protected function revenueByMethod(): array
    {
        return [];
    }

    /**
     * Invoice status breakdown — counts + totals for each status.
     */
    protected function invoiceStatusBreakdown(): array
    {
        $statuses = ['draft', 'sent', 'paid', 'overdue', 'void'];
        $result = [];

        foreach ($statuses as $status) {
            $result[$status] = [
                'count' => PlatformInvoice::where('status', $status)->count(),
                'total' => (float) PlatformInvoice::where('status', $status)->sum('total'),
            ];
        }

        return $result;
    }
}