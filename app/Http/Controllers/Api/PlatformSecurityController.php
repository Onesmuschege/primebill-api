<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Services\Platform\PlatformSettingsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-level security view for the operator console. Surfaces security
 * events, suspicious activity, and authentication anomalies across every
 * tenant on PrimeBill.
 *
 * All data is real — aggregated from SystemLog (which records auth and
 * security actions platform-wide). The tenant-scoped SecurityEvent model is
 * NOT used here because it is per-tenant; this controller reads the cross-tenant
 * SystemLog instead.
 */
class PlatformSecurityController extends Controller
{
    use ApiResponse;

    public function __construct(protected PlatformSettingsService $settings) {}

    /**
     * GET /api/platform/security/events
     *
     * Recent security-relevant events across the platform. Paginated,
     * filterable by severity/action. Reads from SystemLog where the action
     * starts with 'security.' or 'auth.'.
     */
    public function events(Request $request)
    {
        $request->validate([
            'action' => 'nullable|string|max:100',
            'severity' => 'nullable|in:info,warning,critical,all',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = SystemLog::with('user');

        // Security + auth events only.
        $query->where(function ($q) {
            $q->where('action', 'like', 'security.%')
                ->orWhere('action', 'like', 'auth.%');
        });

        if ($request->filled('action')) {
            $query->where('action', 'like', "%{$request->string('action')}%");
        }

        // Severity filtering: critical events are those with specific action patterns.
        $severity = $request->string('severity')->toString();
        if ($severity !== '' && $severity !== 'all') {
            match ($severity) {
                'critical' => $query->where(function ($q) {
                    $q->where('action', 'like', 'security.%')
                        ->orWhere('action', 'like', 'auth.login.failed%');
                }),
                'warning' => $query->where('action', 'like', 'auth.login.failed%'),
                'info' => $query->where('action', 'like', 'auth.%'),
                default => null,
            };
        }

        $perPage = $request->integer('per_page', 25);
        $events = $query->orderByDesc('created_at')->paginate($perPage);

        return $this->success($events);
    }

    /**
     * GET /api/platform/security/suspicious
     *
     * Suspicious activity: IPs with excessive failed login attempts and
     * recent brute-force patterns. Real data from SystemLog.
     */
    public function suspicious()
    {
        // Threshold + window are operator-configurable via the platform
        // Settings page (PlatformSettingsService) — defaults 5 / 7 days.
        $threshold = (int) $this->settings->get('failed_login_threshold');
        $windowDays = (int) $this->settings->get('suspicious_window_days');
        $since = now()->subDays($windowDays);

        // IPs with more than $threshold failed attempts in the window.
        $suspiciousIps = SystemLog::where('action', 'like', 'auth.login.failed%')
            ->where('created_at', '>=', $since)
            ->whereNotNull('ip_address')
            ->selectRaw('ip_address, count(*) as attempts')
            ->groupBy('ip_address')
            ->havingRaw('count(*) > ?', [$threshold])
            ->orderByDesc('attempts')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'ip' => $r->ip_address,
                'attempts' => (int) $r->attempts,
            ])
            ->toArray();

        // Recent failed logins (last 24h) with IP.
        $recentFailures = SystemLog::where('action', 'like', 'auth.login.failed%')
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'action' => $r->action,
                'ip_address' => $r->ip_address,
                'created_at' => $r->created_at?->toISOString(),
                'details' => $r->old_values,
            ])
            ->toArray();

        return $this->success([
            'suspicious_ips' => $suspiciousIps,
            'recent_failures' => $recentFailures,
            'threshold' => $threshold,
            'window_days' => $windowDays,
        ]);
    }

    /**
     * GET /api/platform/security/overview
     *
     * Headline security metrics for the Security Center cards. Computed
     * directly from the cross-tenant SystemLog feed with real counts.
     */
    public function overview(): \Illuminate\Http\JsonResponse
    {
        $today = now()->toDateString();
        $thisWeek = now()->subDays(7);

        return $this->success([
            'failed_logins_today' => SystemLog::where('action', 'like', 'auth.login.failed%')
                ->whereDate('created_at', $today)->count(),
            'failed_logins_this_week' => SystemLog::where('action', 'like', 'auth.login.failed%')
                ->where('created_at', '>=', $thisWeek)->count(),
            'successful_logins_today' => SystemLog::where('action', 'auth.login.success')
                ->whereDate('created_at', $today)->count(),
            'successful_logins_this_week' => SystemLog::where('action', 'auth.login.success')
                ->where('created_at', '>=', $thisWeek)->count(),
            'security_events_this_week' => SystemLog::where('action', 'like', 'security.%')
                ->where('created_at', '>=', $thisWeek)->count(),
        ]);
    }
}
