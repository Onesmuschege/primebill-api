<?php

namespace Database\Seeders;

use App\Models\Router;
use App\Models\RouterConfiguration;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RouterConfigurationSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $routers = Router::where('tenant_id', $tenant->id)->get();

            foreach ($routers as $router) {
                RouterConfiguration::updateOrCreate(
                    ['router_id' => $router->id, 'name' => 'Default Configuration'],
                    [
                        'tenant_id' => $tenant->id,
                        'router_id' => $router->id,
                        'name' => 'Default Configuration',
                        'configuration' => json_encode(['identity' => $router->name, 'snmp_community' => 'public']),
                        'is_active' => true,
                    ]
                );
            }

            $this->command->line("  [{$tenant->slug}] Router configurations seeded.");
        });

        $this->command->info('RouterConfigurationSeeder: complete.');
    }
}