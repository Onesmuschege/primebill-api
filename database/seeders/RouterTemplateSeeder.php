<?php

namespace Database\Seeders;

use App\Models\RouterTemplate;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RouterTemplateSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $templates = [
                ['name' => 'MikroTik hAP ac2 Config', 'router_type' => 'mikrotik', 'description' => 'Default home router profile', 'base_configuration' => ['system' => ['identity' => 'MikroTik hAP ac2']]],
                ['name' => 'MikroTik RB750Gr3 Config', 'router_type' => 'mikrotik', 'description' => 'SOHO router profile', 'base_configuration' => ['system' => ['identity' => 'RB750Gr3']]],
                ['name' => 'Cisco 2901 Config', 'router_type' => 'cisco', 'description' => 'Business edge router profile', 'base_configuration' => ['hostname' => 'Cisco-Router']],
            ];

            foreach ($templates as $template) {
                RouterTemplate::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $template['name']],
                    array_merge($template, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($templates) . " router templates seeded.");
        });

        $this->command->info('RouterTemplateSeeder: complete.');
    }
}