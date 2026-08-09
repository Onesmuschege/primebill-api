<?php

namespace Database\Seeders;

use App\Models\ServiceProfile;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class ServiceProfileSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $profiles = [
                ['name' => 'Standard QoS', 'description' => 'Standard quality of service profile', 'upload_speed' => 5000, 'download_speed' => 5000, 'priority' => 'normal'],
                ['name' => 'Business QoS', 'description' => 'Business priority QoS', 'upload_speed' => 10000, 'download_speed' => 10000, 'priority' => 'high'],
                ['name' => 'Gaming QoS', 'description' => 'Low latency gaming profile', 'upload_speed' => 2000, 'download_speed' => 8000, 'priority' => 'high'],
            ];

            foreach ($profiles as $profile) {
                ServiceProfile::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $profile['name']],
                    array_merge($profile, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($profiles) . " service profiles seeded.");
        });

        $this->command->info('ServiceProfileSeeder: complete.');
    }
}