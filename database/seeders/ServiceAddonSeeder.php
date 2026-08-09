<?php

namespace Database\Seeders;

use App\Models\ServiceAddon;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class ServiceAddonSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $addons = [
                ['name' => 'Static IP', 'description' => 'Dedicated static IP address', 'price' => 500.00],
                ['name' => 'Priority Support', 'description' => '24/7 priority technical support', 'price' => 1000.00],
                ['name' => 'Web Hosting', 'description' => 'Basic web hosting package', 'price' => 1500.00],
            ];

            foreach ($addons as $addon) {
                ServiceAddon::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $addon['name']],
                    array_merge($addon, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($addons) . " service addons seeded.");
        });

        $this->command->info('ServiceAddonSeeder: complete.');
    }
}
