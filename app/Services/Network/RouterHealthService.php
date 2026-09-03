<?php

namespace App\Services\Network;

use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * RouterHealthService — live reachability & sync state for routers
 * (Core ISP Gate — Sections 43/44).
 *
 * Distinguishes the three states an operator actually needs:
 *
 *   CONFIGURED   — a router row exists with credentials
 *   REACHABLE    — a live RouterOS probe succeeded just now
 *   SYNCHRONIZED — the last successful communication is recent
 *
 * The DB `status` column alone is NOT proof the router is up (Section 43).
 * Every probe result is persisted: health_state, last_health_check_at,
 * last_health_error — and last_seen/last_sync_at on success — so a cached
 * summary can never claim reachability without fresh evidence.
 */
class RouterHealthService
{
    public const HEALTHY     = 'healthy';
    public const DEGRADED    = 'degraded';
    public const UNAVAILABLE = 'unavailable';
    public const UNKNOWN     = 'unknown';

    /** Seconds after which a HEALTHY probe result is considered stale. */
    protected int $staleAfterSeconds;

    public function __construct(protected MikroTikService $mikrotik)
    {
        $this->staleAfterSeconds = (int) config('network.router_stale_after_seconds', 900);
    }

    /**
     * Probe one router live and persist the outcome.
     *
     * @return array{router_id:int, name:string, configured:bool,
     *   reachable:bool, synchronized:bool, provisioning_ready:bool,
     *   health_state:string, label:string, routeros_version:?string,
     *   last_sync_age_seconds:?int, last_health_error:?string}
     */
    public function check(Router $router): array
    {
        $reachable = false;
        $version   = null;
        $error     = null;

        try {
            if ($this->mikrotik->connect($router) && $this->mikrotik->testConnection()) {
                $reachable = true;

                $resources = $this->mikrotik->getRouterResources();
                $version   = $resources['version'] ?? null;

                $router->forceFill([
                    'health_state'         => self::HEALTHY,
                    'last_health_check_at' => now(),
                    'last_health_error'    => null,
                    'last_seen'            => now(),
                    'last_sync_at'         => now(),
                    'routeros_version'     => $version ?: $router->routeros_version,
                ])->save();

                Log::info('RouterHealthService: router healthy', [
                    'router_id' => $router->id,
                    'name'      => $router->name,
                    'version'   => $version,
                ]);
            } else {
                $error = 'Router did not respond to the RouterOS API probe';

                $router->forceFill([
                    'health_state'         => self::UNAVAILABLE,
                    'last_health_check_at' => now(),
                    'last_health_error'    => $error,
                ])->save();

                Log::warning('RouterHealthService: router unreachable', [
                    'router_id' => $router->id,
                    'name'      => $router->name,
                ]);
            }
        } catch (\Throwable $e) {
            $reachable = false;
            $error     = $e->getMessage();

            $router->forceFill([
                'health_state'         => self::UNAVAILABLE,
                'last_health_check_at' => now(),
                'last_health_error'    => $error,
            ])->save();

            Log::error('RouterHealthService: probe threw', [
                'router_id' => $router->id,
                'error'     => $error,
            ]);
        }

        return $this->buildState($router, $reachable, $version, $error);
    }

    /**
     * Sweep every router (optionally scoped to one tenant) and persist each
     * probe outcome. Command + healthAll endpoint call this.
     *
     * @return array{checked:int, healthy:int, unavailable:int, results:array}
     */
    public function checkAll(?int $tenantId = null): array
    {
        $query = Router::query();

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $checked     = 0;
        $healthy     = 0;
        $unavailable = 0;
        $results     = [];

        foreach ($query->get() as $router) {
            $result = $this->check($router);
            $results[] = $result;
            $checked++;

            if ($result['reachable']) {
                $healthy++;
            } else {
                $unavailable++;
            }
        }

        return [
            'checked'     => $checked,
            'healthy'     => $healthy,
            'unavailable' => $unavailable,
            'results'     => $results,
        ];
    }

    /**
     * Classify the router's current state WITHOUT probing (DB-only truth).
     * Used for list views so we don't hammer every router on every page load.
     */
    public function summarize(Router $router): array
    {
        return $this->buildState($router, false, $router->routeros_version, $router->last_health_error);
    }

    /**
     * Reported health state without a live probe — based on the persisted
     * outcome of the most recent check plus staleness.
     */
    public function reportedState(Router $router): string
    {
        if ($router->health_state === null) {
            return self::UNKNOWN;
        }

        // A previously-healthy router whose last check is stale is degraded,
        // not silently "healthy" (Section 44: CONFIGURED ≠ REACHABLE).
        if ($router->health_state === self::HEALTHY && $router->last_health_check_at) {
            $staleAfter = now()->subSeconds($this->staleAfterSeconds);

            if ($router->last_health_check_at->lt($staleAfter)) {
                return self::DEGRADED;
            }
        }

        return $router->health_state;
    }

    /**
     * The single state-shaping function used by both live probes and cached
     * summaries, so every caller receives the same contract.
     */
    protected function buildState(Router $router, bool $reachable, ?string $version, ?string $error): array
    {
        $syncFresh = $router->last_sync_at !== null
            && now()->diffInSeconds($router->last_sync_at) <= $this->staleAfterSeconds;

        $synchronized = $reachable || ($syncFresh && $router->health_state === self::HEALTHY);

        $healthState = $reachable
            ? self::HEALTHY
            : ($router->health_state ?: self::UNKNOWN);

        $label = match ($healthState) {
            self::HEALTHY     => 'Reachable — Synchronized',
            self::DEGRADED    => 'Configured — Degraded',
            self::UNAVAILABLE => 'Configured — Unreachable',
            default           => 'Configured — Never Probed',
        };

        return [
            'router_id'             => $router->id,
            'name'                  => $router->name,
            'configured'            => true, // a row with credentials exists
            'reachable'             => $reachable,
            'synchronized'          => $synchronized,
            'provisioning_ready'    => $reachable,
            'health_state'          => $healthState,
            'label'                 => $label,
            'routeros_version'      => $version,
            'last_health_check_at'  => $router->last_health_check_at?->toIso8601String(),
            'last_sync_at'          => $router->last_sync_at?->toIso8601String(),
            'last_sync_age_seconds' => $router->last_sync_at
                ? (int) now()->diffInSeconds($router->last_sync_at)
                : null,
            'last_health_error'     => $error ?: $router->last_health_error,
            'error'                 => $error ?: $router->last_health_error,
        ];
    }
}