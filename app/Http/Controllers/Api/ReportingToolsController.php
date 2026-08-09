<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\SavedReport;

/**
 * ReportingToolsController
 *
 * Domain M — Analytics/Reporting: saved reports, schedules, deliveries and
 * custom dashboards/widgets.
 */
class ReportingToolsController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'saved-reports' => [
            'model' => SavedReport::class,
            'search' => ['name', 'code'],
            'with' => ['creator'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'report-schedules' => [
            'model' => ReportSchedule::class,
            'search' => ['name'],
            'rules' => [
                'saved_report_id' => 'required|exists:saved_reports,id',
                'name' => 'required|string|max:255',
            ],
        ],
        'report-deliveries' => [
            'model' => ReportDelivery::class,
            'search' => ['status'],
            'rules' => ['report_schedule_id' => 'nullable|exists:report_schedules,id'],
        ],
        'dashboards' => [
            'model' => Dashboard::class,
            'search' => ['name', 'code'],
            'with' => ['widgets'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'dashboard-widgets' => [
            'model' => DashboardWidget::class,
            'search' => ['name', 'code'],
            'rules' => [
                'dashboard_id' => 'required|exists:dashboards,id',
                'name' => 'required|string|max:255',
            ],
        ],
    ];
}
