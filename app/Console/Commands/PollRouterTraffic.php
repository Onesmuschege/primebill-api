<?php

namespace App\Console\Commands;

use App\Models\NetworkTraffic;
use App\Models\Router;
use App\Services\Network\MikroTikService;
use Illuminate\Console\Command;

class PollRouterTraffic extends Command
{
    protected $signature = 'network:poll-traffic {--router= : Poll a specific router ID}';

    protected $description = 'Poll MikroTik routers for interface traffic statistics';

    public function handle(MikroTikService $mikrotik): int
    {
        $routers = $this->option('router')
            ? Router::where('id', $this->option('router'))->get()
            : Router::where('type', 'mikrotik')->get();

        if ($routers->isEmpty()) {
            $this->warn('No routers configured.');

            return self::SUCCESS;
        }

        $polled = 0;

        foreach ($routers as $router) {
            if (!$mikrotik->connect($router)) {
                $router->update(['status' => 'offline']);
                $this->warn("Could not connect to router {$router->name} ({$router->ip_address})");

                continue;
            }

            $stats = $mikrotik->getTrafficStats();

            NetworkTraffic::create([
                'router_id'   => $router->id,
                'interface'   => $stats['name'] ?? 'ether1',
                'rx_bytes'    => (int) ($stats['rx-byte'] ?? 0),
                'tx_bytes'    => (int) ($stats['tx-byte'] ?? 0),
                'recorded_at' => now(),
            ]);

            $router->update([
                'status'    => 'online',
                'last_seen' => now(),
            ]);

            $polled++;
            $this->line("Polled {$router->name}");
        }

        $this->info("Polled {$polled} router(s).");

        return self::SUCCESS;
    }
}
