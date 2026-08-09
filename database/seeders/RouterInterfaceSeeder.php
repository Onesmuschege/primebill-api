<?php

namespace Database\Seeders;

use App\Models\Router;
use App\Models\RouterInterface;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RouterInterfaceSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $routers = Router::where('tenant_id', $tenant->id)->get();

            foreach ($routers as $router) {
                $interfaces = [
                    ['router_id' => $router->id, 'name' => 'ether1-WAN', 'type' => 'ethernet', 'mac_address' => $this->randomMac(), 'status' => 'up', 'ip_address' => $router->ip_address],
                    ['router_id' => $router->id, 'name' => 'ether2-LAN', 'type' => 'ethernet', 'mac_address' => $this->randomMac(), 'status' => 'up', 'ip_address' => '10.0.0.1'],
                    ['router_id' => $router->id, 'name' => 'wlan1', 'type' => 'wireless', 'mac_address' => $this->randomMac(), 'status' => 'up', 'ip_address' => null],
                ];

                foreach ($interfaces as $interface) {
                    RouterInterface::updateOrCreate(
                        ['router_id' => $router->id, 'name' => $interface['name']],
                        array_merge($interface, ['tenant_id' => $tenant->id])
                    );
                }
            }

            $this->command->line("  [{$tenant->slug}] Router interfaces seeded.");
        });

        $this->command->info('RouterInterfaceSeeder: complete.');
    }

    private function randomMac(): string
    {
        return implode(':', array_map(fn () => dechex(rand(0, 255)), range(1, 6)));
    }
}