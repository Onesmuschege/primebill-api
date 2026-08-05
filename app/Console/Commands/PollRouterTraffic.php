<?php

namespace App\Console\Commands;

use App\Models\NetworkTraffic;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\Network\MikroTikService;

class PollRouterTraffic extends Command
{
    protected $signature = 'network:poll-traffic {--router= : Poll a specific router ID}';

    protected $description = 'Poll all active MikroTik routers for interface traffic statistics';

    /**
     * How many interface metric rows were recorded for the most recent poll.
     */
    protected int $interfacesPolled = 0;

    public function handle(MikroTikService $mikrotik): int
    {
        $polled       = 0;
        $failed       = 0;
        $skipped      = 0;
        $interfaces   = 0;
        $routerOption = $this->option('router');

        // If polling a specific router, resolve it directly (no tenant context).
        if ($routerOption) {
            $router = Router::find($routerOption);

            if (!$router) {
                $this->error("Router #{$routerOption} not found.");

                return self::FAILURE;
            }

            $ok = $this->pollRouter($mikrotik, $router);

            return $ok === true ? self::SUCCESS : self::FAILURE;
        }

        // Otherwise iterate every tenant and poll that tenant's active routers.
        $tenants = Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found. Nothing to poll.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            app()->instance('currentTenant', $tenant);

            $routers = Router::query()
                ->where('status', '!=', 'disabled')
                ->get();

            if ($routers->isEmpty()) {
                $skipped++;
                continue;
            }

            foreach ($routers as $router) {
                $result = $this->pollRouter($mikrotik, $router);

                if ($result === true) {
                    $polled++;
                    $interfaces += $this->interfacesPolled;
                } elseif ($result === false) {
                    $failed++;
                }
            }
        }

        // Clear tenant context so we don't leak it into later process work.
        app()->forgetInstance('currentTenant');

        $this->info("Polled {$polled} router(s) across {$tenants->count()} tenant(s); {$interfaces} interface metric(s); {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Poll a single router and persist its interface traffic.
     *
     * @return bool|null true on success, false on failure, null when skipped
     */
    protected function pollRouter(MikroTikService $mikrotik, Router $router): ?bool
    {
        $this->interfacesPolled = 0;

        // Only poll MikroTik routers that we can actually talk to over the API.
        if ($router->type !== 'mikrotik') {
            $this->line("Skipping non-MikroTik router {$router->name} (type: {$router->type})");

            return null;
        }

        if (!$mikrotik->connect($router)) {
            $router->update(['status' => 'offline']);
            $this->warn("Could not connect to router {$router->name} ({$router->ip_address})");
            Log::warning("PollRouterTraffic: could not connect to router {$router->name}", ['router_id' => $router->id]);

            return false;
        }

        try {
            $interfaces = $mikrotik->getAllInterfacesTraffic();

            if (empty($interfaces)) {
                $this->warn("No interface data returned from {$router->name}");
            }

            foreach ($interfaces as $iface) {
                $name    = $iface['name']  ?? 'unknown';
                $rxBytes = (int) ($iface['rx-byte'] ?? 0);
                $txBytes = (int) ($iface['tx-byte'] ?? 0);

                NetworkTraffic::create([
                    'router_id'   => $router->id,
                    'interface'   => $name,
                    'rx_bytes'    => $rxBytes,
                    'tx_bytes'    => $txBytes,
                    'recorded_at' => now(),
                ]);

                $this->interfacesPolled++;
            }

            $router->update([
                'status'    => 'online',
                'last_seen' => now(),
            ]);

            $this->line("Polled {$router->name} ({$this->interfacesPolled} interface(s))");

            return true;
        } catch (\Throwable $e) {
            $router->update(['status' => 'offline']);
            Log::error("PollRouterTraffic: error polling router {$router->name}: {$e->getMessage()}", [
                'router_id' => $router->id,
            ]);
            $this->error("Error polling {$router->name}: {$e->getMessage()}");

            return false;
        }
    }
}
