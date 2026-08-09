<?php

namespace Database\Seeders;

use App\Models\Router;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RouterManagementSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $routers = [
                ['name' => 'Core-Router-01', 'ip_address' => '10.0.0.1', 'model' => 'MikroTik CCR1036', 'location' => 'Data Center', 'status' => 'online'],
                ['name' => 'Edge-Router-North', 'ip_address' => '10.0.1.1', 'model' => 'MikroTik RB4011', 'location' => 'North POP', 'status' => 'online'],
                ['name' => 'Edge-Router-South', 'ip_address' => '10.0.2.1', 'model' => 'MikroTik RB4011', 'location' => 'South POP', 'status' => 'online'],
                ['name' => 'Backup-Router', 'ip_address' => '10.0.0.2', 'model' => 'Cisco 2901', 'location' => 'Data Center', 'status' => 'offline'],
            ];

            foreach ($routers as $router) {
                Router::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $router['name']],
                    array_merge($router, [
                        'tenant_id' => $tenant->id,
                        'username' => 'admin',
                        'password' => 'demo_password',
                        'port' => 8728,
                        'type' => 'mikrotik',
                        'device_type' => 'router',
                        'nas_identifier' => $router['name'],
                        'nas_type' => 'mikrotik',
                        'routeros_version' => '7.14',
                        'capabilities' => ['pppoe', 'hotspot', 'dhcp'],
                        'last_seen' => now(),
                    ])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($routers) . " routers seeded.");
        });

        $this->command->info('RouterManagementSeeder: complete.');
    }
}