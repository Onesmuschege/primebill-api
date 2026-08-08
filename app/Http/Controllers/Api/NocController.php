<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceMetric;
use App\Models\NetworkAlert;
use App\Models\NetworkLink;
use App\Models\Router;
use App\Services\Network\AlertService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Phase 4 — Network Operations Center controller.
 *
 * Exposes the NOC dashboard KPIs, device list, per-device metrics,
 * alert lifecycle, and topology links.
 */
class NocController extends Controller
{
    use ApiResponse;

    public function __construct(protected AlertService $alerts)
    {
    }

    /**
     * NOC overview KPIs.
     */
    public function overview(Request $request)
    {
        $totalDevices   = Router::count();
        $onlineDevices  = Router::where('status', 'online')->count();
        $offlineDevices = Router::where('status', 'offline')->count();
        $openAlerts     = NetworkAlert::where('status', 'open')->count();
        $criticalAlerts = NetworkAlert::where('status', 'open')->where('severity', 'critical')->count();
        $linksUp        = NetworkLink::where('status', 'up')->count();
        $linksDown      = NetworkLink::where('status', 'down')->count();

        return $this->success([
            'total_devices'    => $totalDevices,
            'online_devices'   => $onlineDevices,
            'offline_devices'  => $offlineDevices,
            'open_alerts'      => $openAlerts,
            'critical_alerts'  => $criticalAlerts,
            'links_up'         => $linksUp,
            'links_down'       => $linksDown,
            'device_health'    => $totalDevices > 0 ? round(($onlineDevices / $totalDevices) * 100, 1) : 0,
        ]);
    }

    /**
     * List devices (routers + NOC device types).
     */
    public function devices(Request $request)
    {
        $devices = Router::query()
            ->withCount(['openAlerts', 'metrics'])
            ->with(['alerts' => fn ($q) => $q->where('status', 'open')])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('device_type'), fn ($q, $v) => $q->where('device_type', $v))
            ->when($request->input('search'), function ($q, $v) {
                $q->where('name', 'like', "%{$v}%")
                  ->orWhere('ip_address', 'like', "%{$v}%")
                  ->orWhere('model', 'like', "%{$v}%")
                  ->orWhere('vendor', 'like', "%{$v}%");
            })
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return $this->success($devices);
    }

    /**
     * Show a single device with its latest metrics and open alerts.
     */
    public function showDevice(Request $request, Router $router)
    {
        $router->load(['alerts' => fn ($q) => $q->where('status', 'open')]);

        $latestMetrics = DeviceMetric::query()
            ->where('device_id', $router->id)
            ->orderByDesc('recorded_at')
            ->limit(1)
            ->latest()
            ->select('metric_type', 'value', 'interface', 'unit', 'recorded_at')
            ->get();

        // Latest reading per metric type.
        $latestByType = DeviceMetric::query()
            ->where('device_id', $router->id)
            ->orderByDesc('recorded_at')
            ->get()
            ->groupBy('metric_type')
            ->map(fn ($group) => $group->first());

        return $this->success([
            'device'        => $router,
            'latest_metrics' => $latestByType,
            'open_alerts'   => $router->alerts,
        ]);
    }

    /**
     * List metrics for a device (optionally filtered by type, paginated).
     */
    public function metrics(Request $request, Router $router)
    {
        $metrics = DeviceMetric::query()
            ->where('device_id', $router->id)
            ->when($request->input('metric_type'), fn ($q, $v) => $q->where('metric_type', $v))
            ->when($request->input('interface'), fn ($q, $v) => $q->where('interface', $v))
            ->when($request->input('from'), fn ($q, $v) => $q->where('recorded_at', '>=', $v))
            ->when($request->input('to'), fn ($q, $v) => $q->where('recorded_at', '<=', $v))
            ->orderByDesc('recorded_at')
            ->paginate($request->input('per_page', 50));

        return $this->success($metrics);
    }

    /**
     * List network alerts (filterable, paginated).
     */
    public function alerts(Request $request)
    {
        $alerts = NetworkAlert::query()
            ->with(['device:id,name,ip_address'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->when($request->input('alert_type'), fn ($q, $v) => $q->where('alert_type', $v))
            ->when($request->input('device_id'), fn ($q, $v) => $q->where('device_id', $v))
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 25));

        return $this->success($alerts);
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledgeAlert(Request $request, NetworkAlert $alert)
    {
        $alert = $this->alerts->acknowledge($alert, $request->user());

        return $this->success($alert, 'Alert acknowledged');
    }

    /**
     * Resolve an alert.
     */
    public function resolveAlert(Request $request, NetworkAlert $alert)
    {
        $alert = $this->alerts->resolve($alert, $request->user());

        return $this->success($alert, 'Alert resolved');
    }

    /**
     * List topology links.
     */
    public function links(Request $request)
    {
        $links = NetworkLink::query()
            ->with([
                'deviceA:id,name,ip_address,device_type',
                'deviceB:id,name,ip_address,device_type',
            ])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('media'), fn ($q, $v) => $q->where('media', $v))
            ->orderBy('created_at')
            ->paginate($request->input('per_page', 25));

        return $this->success($links);
    }

    /**
     * Create a topology link between two devices.
     */
    public function storeLink(Request $request)
    {
        $data = $request->validate([
            'device_a_id' => 'required|exists:routers,id',
            'device_b_id' => 'required|exists:routers,id|different:device_a_id',
            'interface_a' => 'sometimes|nullable|string|max:191',
            'interface_b' => 'sometimes|nullable|string|max:191',
            'media'       => 'sometimes|in:fiber,copper,wireless',
            'status'      => 'sometimes|in:up,down,degraded',
            'description' => 'sometimes|nullable|string|max:191',
        ]);

        $link = NetworkLink::create($data);

        return $this->success($link->load(['deviceA', 'deviceB']), 'Link created', 201);
    }

    public function updateLink(Request $request, NetworkLink $link)
    {
        $data = $request->validate([
            'interface_a' => 'sometimes|nullable|string|max:191',
            'interface_b' => 'sometimes|nullable|string|max:191',
            'media'       => 'sometimes|in:fiber,copper,wireless',
            'status'      => 'sometimes|in:up,down,degraded',
            'description' => 'sometimes|nullable|string|max:191',
        ]);

        $link->update($data);

        return $this->success($link, 'Link updated');
    }

    public function destroyLink(NetworkLink $link)
    {
        $link->delete();

        return $this->success(null, 'Link deleted');
    }
}
