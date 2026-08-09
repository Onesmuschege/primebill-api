<?php

namespace Database\Seeders;

use App\Models\RadiusAttribute;
use App\Models\RadiusProfile;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RadiusManagementSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            // RADIUS profiles - use globally unique codes
            $codeOffset = match($tenant->slug) {
                'primenet-isp' => 100,
                'swiftlink-communications' => 200,
                'metrowave-internet' => 300,
            };

            $profiles = [
                ['name' => 'Default-PPPoE', 'description' => 'Standard PPPoE profile', 'code' => (string)($codeOffset + 1)],
                ['name' => 'Hotspot-Users', 'description' => 'Hotspot user profile', 'code' => (string)($codeOffset + 2)],
                ['name' => 'Corporate-VPN', 'description' => 'Corporate VPN with L2TP', 'code' => (string)($codeOffset + 3)],
            ];

            foreach ($profiles as $profile) {
                RadiusProfile::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $profile['name']],
                    array_merge($profile, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($profiles) . " RADIUS profiles seeded.");
        });

        $this->command->info('RadiusManagementSeeder: complete.');
    }
}