<?php

namespace Database\Seeders;

use App\Models\ProvisioningProfile;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class ProvisioningProfileSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $profiles = [
                ['name' => 'Standard Provisioning', 'description' => 'Standard provisioning workflow', 'auto_activate' => true, 'sla_hours' => 24],
                ['name' => 'Express Provisioning', 'description' => 'Express 4-hour provisioning', 'auto_activate' => true, 'sla_hours' => 4],
                ['name' => 'Corporate Onboarding', 'description' => 'Multi-step corporate onboarding', 'auto_activate' => false, 'sla_hours' => 48],
            ];

            foreach ($profiles as $profile) {
                ProvisioningProfile::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $profile['name']],
                    array_merge($profile, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($profiles) . " provisioning profiles seeded.");
        });

        $this->command->info('ProvisioningProfileSeeder: complete.');
    }
}
