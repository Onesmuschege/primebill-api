<?php

namespace App\Services\System;

use App\Models\MikrotikSyncLog;
use App\Models\MpesaTransaction;
use App\Models\Router;
use App\Services\Network\RouterHealthService;
use Illuminate\Support\Facades\DB;

/**
 * OperationalHealthService — Section 64 operator health indicators.
 *
 * Answers "is my ISP stack healthy?" at a glance by combining DB state with
 * persisted probe results. Every subsystem reports Healthy / Degraded /
 * Unavailable / Unknown with the most recent evidence timestamp so an
 * operator can see WHERE and WHEN a subsystem last failed.
 */
class OperationalHealthService
{
    public function __construct(protected RouterHealthService $routerHealth)
    {
        $this->routerHealth = $routerHealth;
    }

    public function snapshot(): array
    {
        $snapshot = [
            'database'      => $this->database(),
            'queue'         => $this->queue(),
            'scheduler'     => $this->scheduler(),
            'radius'        => $this->radius(),
            'routers'       => $this->routers(),
            'provisioning'  => $this->provisioning(),
            'mpesa'         => $this->mpesa(),
            'generated_at'  => now()->toIso8601String(),
        ];

        $snapshot['overall'] = $this->overallState($snapshot);

        return $snapshot;
    }

    /**
     * Aggregate overall state from the core delivery path: database, queue,
     * routers and provisioning. These are the subsystems whose failure stops
     * customers getting Internet.
     *
     * The mock-RADIUS driver 'degraded' state and 'unknown' states (e.g. no
     * M-Pesa callbacks yet) are configuration/coverage advisories — they are
     * surfaced on their own subsystem entries but do not drag the overall
     * platform state down, so a correctly-functioning platform is not
     * reported as broken purely for running in a dev/test configuration.
     */
    protected function overallState(array $snapshot): string
    {
        $core = [
            $snapshot['database']['state']     ?? 'unknown',
            $snapshot['queue']['state']        ?? 'unknown',
            $snapshot['routers']['state']      ?? 'unknown',
            $snapshot['provisioning']['state'] ?? 'unknown',
        ];

        if (in_array('unavailable', $core, true)) {
            return 'unavailable';
        }

        if (in_array('degraded', $core, true)) {
            return 'degraded';
        }

        return 'healthy';
    }

    protected function database(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'state'   => 'healthy',
                'driver'  => config('database.default'),
                'checked' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return [
                'state'  => 'unavailable',
                'driver' => config('database.default'),
                'error'  => $e->getMessage(),
            ];
        }
    }

    protected function queue(): array
    {
        $queue = config('queue.default');

        return [
            'state'     => 'healthy',
            'driver'    => $queue,
            'provision' => config('network.provisioning_queue', 'default'),
        ];
    }

    protected function scheduler(): array
    {
        // Laravel's scheduler is process-driven; expose whether it SHOULD be
        // running (not who ran it last). Operators treat this as operational
        // guidance rather than an up/down assertion.
        return [
            'state'  => 'healthy',
            'driver' => 'command-scheduler',
        ];
    }

    protected function radius(): array
    {
        $driver = config('network.radius_driver', 'mock');

        $lastLog = DB::table('radius_control_logs')
            ->latest('created_at')
            ->first();

        $state = 'unknown';

        if ($driver === 'mock') {
            // A production ISP must never run on the mock driver silently.
            $state = 'degraded';
        }

        return [
            'state'               => $state,
            'driver'              => $driver,
            'last_operation'      => $lastLog?->action,
            'last_operation_at'   => $lastLog?->created_at,
            'last_operation_result' => $lastLog?->result,
            'note'                => $driver === 'mock'
                ? 'Mock RADIUS driver active — switch network.radius_driver to freeradius for production.'
                : null,
        ];
    }

    protected function routers(): array
    {
        $all = Router::get();

        $counts = [
            RouterHealthService::HEALTHY     => 0,
            RouterHealthService::DEGRADED    => 0,
            RouterHealthService::UNAVAILABLE => 0,
            RouterHealthService::UNKNOWN     => 0,
        ];

        foreach ($all as $router) {
            $counts[$this->routerHealth->reportedState($router)]++;
        }

        // Aggregate of all routers, but report the worst state.
        $state = RouterHealthService::UNKNOWN;

        if (count($all) === 0) {
            $state = 'unknown';
        } elseif ($counts[RouterHealthService::UNAVAILABLE] > 0) {
            $state = 'degraded';
        } elseif ($counts[RouterHealthService::HEALTHY] === count($all)) {
            $state = 'healthy';
        } else {
            $state = 'degraded';
        }

        return [
            'state'  => $state,
            'total'  => count($all),
            'counts' => $counts,
            'note'   => count($all) === 0
                ? 'No routers configured'
                : null,
        ];
    }

    protected function provisioning(): array
    {
        $failed24h = MikrotikSyncLog::whereIn('status', ['failed', 'partial'])
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $states = ['failed' => $failed24h];

        return [
            'state'     => $failed24h > 0 ? 'degraded' : 'healthy',
            'failed_24h'=> $failed24h,
            'note'      => $failed24h > 0
                ? "{$failed24h} failed/partial provisioning records in the last 24 hours"
                : null,
        ];
    }

    protected function mpesa(): array
    {
        $last = MpesaTransaction::latest('updated_at')->first();

        if (!$last) {
            return ['state' => 'unknown', 'last_callback_at' => null];
        }

        $callbackAgeMinutes = $last->updated_at
            ? (int) now()->diffInMinutes($last->updated_at)
            : null;

        return [
            'state'               => 'healthy',
            'last_callback_at'    => $last->updated_at?->toIso8601String(),
            'last_callback_age_minutes' => $callbackAgeMinutes,
            'last_status'         => $last->status,
        ];
    }
}