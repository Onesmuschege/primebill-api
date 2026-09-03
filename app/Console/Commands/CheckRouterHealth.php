<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Network\RouterHealthService;
use Illuminate\Console\Command;

/**
 * network:check-router-health — live RouterOS probe sweep (Sections 43/44).
 *
 * Distinguishes CONFIGURED from REACHABLE from SYNCHRONIZED and persists
 * the outcome per router, so an unreachable router can never appear
 * operational merely because the DB says status = online.
 */
class CheckRouterHealth extends Command
{
    protected $signature = 'network:check-router-health
                            {--tenant= : Check only a specific tenant ID}';

    protected $description = 'Probe all MikroTik routers for live reachability and persist health/sync state';

    public function handle(RouterHealthService $health): int
    {
        $tenantOption = $this->option('tenant');

        if ($tenantOption) {
            $summary = $health->checkAll((int) $tenantOption);
        } else {
            // Sweep across all tenants — RouterHealthService scopes per router.
            $summary = $health->checkAll();
        }

        $this->info("Routers checked: {$summary['checked']}");
        $this->info("Healthy: {$summary['healthy']}  Unreachable: {$summary['unavailable']}");

        foreach ($summary['results'] as $result) {
            $state = $result['health_state'];

            $line = sprintf(
                '[%s] %s (#%d) — %s',
                str_pad($state, 11),
                $result['name'],
                $result['router_id'],
                $result['label']
            );

            if (!empty($result['error'])) {
                $line .= ' — ' . $result['error'];
            }

            if ($state === RouterHealthService::UNAVAILABLE) {
                $this->warn($line);
            } elseif ($state === RouterHealthService::DEGRADED) {
                $this->line($line);
            } else {
                $this->info($line);
            }
        }

        return self::SUCCESS;
    }
}
