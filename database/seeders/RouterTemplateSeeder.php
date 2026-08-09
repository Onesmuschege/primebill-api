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
                ['name' => 'MikroTik hAP ac2 Config', 'vendor' => 'MikroTik', 'model' => 'hAP ac2', 'template' => json_encode(['system' => ['identity' => 'MikroTik']])],
                ['name' => 'MikroTik RB750Gr3 Config', 'vendor' => 'MikroTik', 'model' => 'RB750Gr3', 'template' => json_encode(['system' => ['identity' => 'MikroTik']])],
                ['name' => 'Cisco 2901 Config', 'vendor' => 'Cisco', 'model' => '2901', 'template' => json_encode(['hostname' => 'Cisco-Router'])],
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