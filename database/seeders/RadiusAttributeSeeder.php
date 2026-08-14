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
                ['radius_profile_id' => $profile->id, 'vendor' => 'MikroTik', 'name' => 'Mikrotik-Rate-Limit', 'type' => 'reply', 'value' => '5M/5M', 'opcode' => '=', 'priority' => 10],
                ['radius_profile_id' => $profile->id, 'vendor' => 'MikroTik', 'name' => 'Mikrotik-Recv-Limit', 'type' => 'reply', 'value' => '5242880', 'opcode' => '=', 'priority' => 20],
                ['radius_profile_id' => $profile->id, 'vendor' => 'MikroTik', 'name' => 'Mikrotik-Xmit-Limit', 'type' => 'reply', 'value' => '5242880', 'opcode' => '=', 'priority' => 30],
            ];

            foreach ($attributes as $attr) {
                RadiusAttribute::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'radius_profile_id' => $attr['radius_profile_id'], 'name' => $attr['name']],
                    array_merge($attr, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($attributes) . " RADIUS attributes seeded.");
        });

        $this->command->info('RadiusAttributeSeeder: complete.');
    }
}