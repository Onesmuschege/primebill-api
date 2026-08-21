<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Cross-tenant reporting for the Platform Console — aggregates real stored
 * data across every ISP tenant on PrimeBill. Deliberately a platform-level
 * concern: it does NOT reimplement the tenant-scoped ReportController /
 * ReportingToolsController, but points the same kind of report UI at
 * /platform/reports/* so a platform operator sees every tenant at once.
 *
 * GET-only for v1. Every route sits in the existing 'platform' prefix group
 * (routes/api.php) and is gated by EnsurePlatformAdmin, so no tenant context
 * is resolved here — the underlying service queries tenant-scoped models
 * with explicit withoutTenantScope().
 */
class PlatformReportController extends Controller
{
    use ApiResponse;

    public function __construct(protected PlatformReportService $reportService) {}

    private function validateDates(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return [$validated['from'], $validated['to']];
    }

    /**
     * GET /api/platform/reports/revenue
     */
    public function revenue(Request $request)
    {
        [$from, $to] = $this->validateDates($request);

        return $this->success($this->reportService->revenue($from, $to));
    }

    /**
     * GET /api/platform/reports/tenants
     */
    public function tenants(Request $request)
    {
        [$from, $to] = $this->validateDates($request);

        return $this->success($this->reportService->tenants($from, $to));
    }

    /**
     * GET /api/platform/reports/usage
     */
    public function usage()
    {
        return $this->success($this->reportService->usage());
    }

    /**
     * GET /api/platform/reports/{type}/export
     *
     * CSV download mirroring the tenant-side ReportController::export()
     * response headers / blob pattern exactly.
     */
    public function export(Request $request, string $type)
    {
        if (! in_array($type, ['revenue', 'tenants'], true)) {
            return $this->error('Unknown report type', null, 422);
        }

        [$from, $to] = $this->validateDates($request);

        // Exports are a read-side action with a side effect (delivery), not a
        // data mutation — log for the Platform Audit Log page like the tenant
        // report export pattern is treated elsewhere.
        Log::info('Platform report exported', ['type' => $type, 'from' => $from, 'to' => $to]);

        $csv = $this->reportService->exportCsv($type, $from, $to);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=platform_{$type}_report.csv",
        ]);
    }
}
