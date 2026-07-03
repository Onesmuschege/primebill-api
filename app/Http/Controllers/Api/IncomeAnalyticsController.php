<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

class IncomeAnalyticsController extends Controller
{
    // GET /api/analytics/income
    public function income()
    {
        // Monthly revenue for last 12 months
        $monthly = Payment::select(
                DB::raw("TO_CHAR(created_at, 'Mon YYYY') as month"),
                DB::raw("DATE_TRUNC('month', created_at) as month_start"),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('month', 'month_start')
            ->orderBy('month_start')
            ->get()
            ->map(fn($r) => ['month' => $r->month, 'total' => (float)$r->total, 'count' => $r->count]);

        // Client growth per month (new signups)
        $clientGrowth = Client::select(
                DB::raw("TO_CHAR(created_at, 'Mon YYYY') as month"),
                DB::raw("DATE_TRUNC('month', created_at) as month_start"),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->groupBy('month', 'month_start')
            ->orderBy('month_start')
            ->get()
            ->map(fn($r) => ['month' => $r->month, 'count' => $r->count]);

        // Payment method breakdown
        $paymentMethods = Payment::select('method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->startOfMonth()->subMonths(3))
            ->groupBy('method')
            ->get()
            ->map(fn($r) => ['method' => strtoupper($r->method), 'total' => (float)$r->total, 'count' => $r->count]);

        // Plan distribution
        $planDist = Plan::withCount('accounts')
            ->where('is_active', true)
            ->having('accounts_count', '>', 0)
            ->orderByDesc('accounts_count')
            ->get(['id', 'name'])
            ->map(fn($p) => ['name' => $p->name, 'count' => $p->accounts_count]);

        return response()->json([
            'success' => true,
            'data' => [
                'monthly'         => $monthly,
                'client_growth'   => $clientGrowth,
                'payment_methods' => $paymentMethods,
                'plan_distribution' => $planDist,
            ],
        ]);
    }
}