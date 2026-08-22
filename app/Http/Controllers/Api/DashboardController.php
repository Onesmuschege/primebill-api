<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    // GET /api/dashboard/stats
    public function stats()
    {
        $stats = $this->dashboardService->getStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    // GET /api/dashboard/traffic
    public function traffic(Request $request)
    {
        $period = $request->get('period', 'day');
        $traffic = $this->dashboardService->getTrafficData($period);

        return response()->json([
            'success' => true,
            'data' => $traffic,
        ]);
    }

    // GET /api/dashboard/top-downloaders
    public function topDownloaders(Request $request)
    {
        // Dashboard-widget friendly: the caller decides how many rows it can
        // render (validated 1–50, service default 10). This is a leaderboard
        // of LIVE radius sessions rather than a fixed dataset, so there is no
        // meaningful grand "total" to report — the widget frames itself as a
        // top-N ranking instead.
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $downloaders = $this->dashboardService->getTopDownloaders(
            $validated['limit'] ?? 10
        );

        return response()->json([
            'success' => true,
            'data' => $downloaders,
        ]);
    }

    // GET /api/analytics/income
    public function incomeAnalytics(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'group_by' => 'nullable|in:day,month,year',
        ]);

        $data = $this->dashboardService->getIncomeAnalytics(
            $request->from,
            $request->to,
            $request->get('group_by', 'day')
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // GET /api/dashboard/analytics
    public function analytics()
    {
        $data = $this->dashboardService->getAnalytics();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // GET /api/dashboard/expenditure-summary
    public function expenditureSummary()
    {
        $data = $this->dashboardService->getExpenditureSummary();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // GET /api/dashboard/invoice-aging
    public function invoiceAging()
    {
        $data = $this->dashboardService->getInvoiceAging();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // GET /api/dashboard/churn-analysis
    public function churnAnalysis()
    {
        $data = $this->dashboardService->getChurnAnalysis();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
