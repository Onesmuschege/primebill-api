<?php

namespace Database\Seeders;

use App\Models\RadiusAttribute;
use App\Models\RadiusProfile;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class RadiusAttributeSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $profile = RadiusProfile::where('tenant_id', $tenant->id)->first();

            if (! $profile) {
                $this->command->warn("RadiusAttributeSeeder [{$tenant->slug}]: No RADIUS profiles found. Skipping.");
                return;
            }

            $attributes = [
                ['profile_id' => $profile->id, 'vendor' => 'MikroTik', 'attribute' => 'Mikrotik-Rate-Limit', 'value' => '5M/5M', 'op' => '='],
                ['profile_id' => $profile->id, 'vendor' => 'MikroTik', 'attribute' => 'Mikrotik-Recv-Limit', 'value' => '5242880', 'op' => '='],
                ['profile_id' => $profile->id, 'vendor' => 'MikroTik', 'attribute' => 'Mikrotik-Xmit-Limit', 'value' => '5242880', 'op' => '='],
            ];

            foreach ($attributes as $attr) {
                RadiusAttribute::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'profile_id' => $attr['profile_id'], 'attribute' => $attr['attribute']],
                    array_merge($attr, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($attributes) . " RADIUS attributes seeded.");
        });

        $this->command->info('RadiusAttributeSeeder: complete.');
    }
}