<?php

namespace Database\Seeders;

use App\Models\RadiusProfile;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RadiusProfileSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $profiles = [
                ['name' => 'Default', 'description' => 'Default RADIUS profile', 'vendor' => 'MikroTik'],
                ['name' => 'Premium', 'description' => 'Premium high-speed profile', 'vendor' => 'MikroTik'],
            ];

            foreach ($profiles as $profile) {
                RadiusProfile::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $profile['name']],
                    array_merge($profile, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($profiles) . " RADIUS profiles seeded.");
        });

        $this->command->info('RadiusProfileSeeder: complete.');
    }
}