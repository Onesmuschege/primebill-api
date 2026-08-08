<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\NetworkEvent;
use App\Models\RadiusControlLog;
use App\Models\RadiusSession;
use App\Models\Router;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NetworkDashboardController extends Controller
{
    /**
     * Network dashboard overview.
     */
    public function overview(Request $request): JsonResponse
    {
        $totalRouters = Router::count();
        $onlineRouters = Router::where('status', 'online')->count();
        $offlineRouters = Router::where('status', 'offline')->count();

        $radiusOnline = NetworkEvent::where('event_type', 'ROUTER_ONLINE')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();
        $radiusErrors = NetworkEvent::whereIn('event_type', ['RADIUS_ACCEPT', 'RADIUS_REJECT'])
            ->where('severity', 'warning')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $activePppoe = RadiusSession::where('access_method', 'pppoe')
            ->where('status', RadiusSession::STATUS_ONLINE)
            ->count();
        $activeHotspot = RadiusSession::where('access_method', 'hotspot')
            ->where('status', RadiusSession::STATUS_ONLINE)
            ->count();

        $suspendedServices = ClientAccount::where('service_state', ClientAccount::STATE_SUSPENDED)->count();
        $provisioningFailures = RadiusControlLog::where('status', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();
        $coaFailures = RadiusControlLog::whereIn('action', ['change_rate_limit', 'apply_policy'])
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $authFailures = NetworkEvent::where('event_type', 'RADIUS_REJECT')
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $totalTraffic = RadiusSession::where('status', RadiusSession::STATUS_ONLINE)->sum('bytes_in')
            + RadiusSession::where('status', RadiusSession::STATUS_ONLINE)->sum('bytes_out');

        return response()->json([
            'routers' => [
                'total'   => $totalRouters,
                'online'  => $onlineRouters,
                'offline' => $offlineRouters,
            ],
            'radius' => [
                'online'      => $radiusOnline,
                'auth_failures' => $authFailures,
            ],
            'sessions' => [
                'active_pppoe'    => $activePppoe,
                'active_hotspot'  => $activeHotspot,
                'total_active'    => $activePppoe + $activeHotspot,
                'traffic_bytes'   => $totalTraffic,
            ],
            'alerts' => [
                'suspended_services'    => $suspendedServices,
                'provisioning_failures' => $provisioningFailures,
                'coa_failures'          => $coaFailures,
            ],
            'last_updated' => now()->toIso8601String(),
        ]);
    }

    /**
     * List all routers with status.
     */
    public function routers(Request $request): JsonResponse
    {
        $routers = Router::select([
            'id', 'name', 'device_type', 'model', 'vendor',
            'routeros_version', 'radius_ip', 'radius_auth_port',
            'radius_acct_port', 'coa_port', 'nas_identifier',
            'status', 'last_seen', 'capabilities',
        ])->orderBy('name')->get();

        return response()->json($routers);
    }

    /**
     * Router detail with metrics.
     */
    public function routerDetail(int $routerId): JsonResponse
    {
        $router = Router::findOrFail($routerId);

        $activeSessions = RadiusSession::where('nas_id', $router->id)
            ->where('status', RadiusSession::STATUS_ONLINE)
            ->count();

        $recentEvents = NetworkEvent::where('nas_id', $router->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'router'           => $router,
            'active_sessions'  => $activeSessions,
            'recent_events'    => $recentEvents,
        ]);
    }

    /**
     * Active RADIUS sessions.
     */
    public function sessions(Request $request): JsonResponse
    {
        $query = RadiusSession::with('account.client', 'nas')
            ->where('status', RadiusSession::STATUS_ONLINE);

        if ($request->filled('access_method')) {
            $query->where('access_method', $request->input('access_method'));
        }

        if ($request->filled('nas_id')) {
            $query->where('nas_id', $request->input('nas_id'));
        }

        $sessions = $query->orderByDesc('session_start')->paginate(50);

        return response()->json($sessions);
    }

    /**
     * Network events log.
     */
    public function events(Request $request): JsonResponse
    {
        $query = NetworkEvent::orderByDesc('created_at');

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        $events = $query->limit(200)->get();

        return response()->json($events);
    }

    /**
     * RADIUS control logs (CoA/Disconnect tracking).
     */
    public function controlLogs(Request $request): JsonResponse
    {
        $query = RadiusControlLog::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $logs = $query->limit(200)->get();

        return response()->json($logs);
    }

    /**
     * RADIUS stats.
     */
    public function radiusStats(Request $request): JsonResponse
    {
        $period = $request->input('period', '24h');

        $since = match ($period) {
            '1h'  => now()->subHour(),
            '24h' => now()->subDay(),
            '7d'  => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subDay(),
        };

        $accept = NetworkEvent::where('event_type', 'RADIUS_ACCEPT')
            ->where('created_at', '>=', $since)->count();
        $reject = NetworkEvent::where('event_type', 'RADIUS_REJECT')
            ->where('created_at', '>=', $since)->count();
        $coaSent = NetworkEvent::where('event_type', 'COA_SENT')
            ->where('created_at', '>=', $since)->count();
        $disconnectSent = NetworkEvent::where('event_type', 'DISCONNECT_SENT')
            ->where('created_at', '>=', $since)->count();

        // Per-hour breakdown for auth failures
        $authFailures = NetworkEvent::where('event_type', 'RADIUS_REJECT')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE_TRUNC(\'hour\', created_at) as hour, COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return response()->json([
            'period'      => $period,
            'since'       => $since->toIso8601String(),
            'accept'      => $accept,
            'reject'      => $reject,
            'coa_sent'    => $coaSent,
            'disconnect_sent' => $disconnectSent,
            'auth_failures_by_hour' => $authFailures,
        ]);
    }
}
