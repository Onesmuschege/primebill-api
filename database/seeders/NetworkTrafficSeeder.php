<?php

namespace Database\Seeders;

use App\Models\NetworkTraffic;
use App\Models\Router;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds network interface traffic samples per router. Idempotent via a
 * per-tenant guard (no unique constraint on network_traffic).
 */
class NetworkTrafficSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $routers = Router::where('tenant_id', $tenant->id)->get();

            if ($routers->isEmpty()) {
                $this->command->warn("NetworkTrafficSeeder [{$tenant->slug}]: No routers found. Skipping.");
                return;
            }

            if (NetworkTraffic::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Network traffic already present — skipped.");
                return;
            }

            $interfaces = ['ether1', 'ether2', 'sfp1', 'bridge1'];
            $created = 0;

            foreach ($routers->take(3) as $rIndex => $router) {
                // 60 samples over the last 7 days.
                for ($i = 0; $i < 60; $i++) {
                    $interface = $interfaces[$i % count($interfaces)];
                    $base = 1000 + (($i + $rIndex) * 37) % 9000; // MB range
                    NetworkTraffic::create([
                        'tenant_id' => $tenant->id,
                        'router_id' => $router->id,
                        'tx_bytes' => $base * 1024 * 1024,
                        'rx_bytes' => ($base * 3) * 1024 * 1024,
                        'interface' => $interface,
                        'recorded_at' => Carbon::now()->subDays(7)->addMinutes($i * 170),
                    ]);
                    $created++;
                }
            }

            $this->command->line("  [{$tenant->slug}] {$created} network traffic samples seeded.");
        });

        $this->command->info('NetworkTrafficSeeder: complete.');
    }
}