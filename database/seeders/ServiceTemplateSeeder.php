<?php

namespace Database\Seeders;

use App\Models\ServiceTemplate;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class ServiceTemplateSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $templates = [
                ['name' => 'Home Internet', 'description' => 'Standard residential internet', 'default_price' => 3500.00, 'features' => json_encode(['WiFi' => true, 'Static IP' => false])],
                ['name' => 'Business Fiber', 'description' => 'Business fiber connection', 'default_price' => 9000.00, 'features' => json_encode(['WiFi' => true, 'Static IP' => true, 'SLA' => true])],
            ];

            foreach ($templates as $template) {
                ServiceTemplate::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $template['name']],
                    array_merge($template, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($templates) . " service templates seeded.");
        });

        $this->command->info('ServiceTemplateSeeder: complete.');
    }
}
