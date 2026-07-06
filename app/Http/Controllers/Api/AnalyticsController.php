<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    protected AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * GET /api/analytics/income
     *
     * Returns:
     * - Monthly revenue
     * - Client growth
     * - Payment method distribution
     */
    public function income(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->analyticsService->getIncomeAnalytics(),
        ]);
    }

    /**
     * GET /api/analytics/summary
     *
     * Optional endpoint for future dashboard widgets.
     */
    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->analyticsService->summary(),
        ]);
    }
}