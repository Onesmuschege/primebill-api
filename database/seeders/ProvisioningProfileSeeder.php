<?php

namespace Database\Seeders;

use App\Models\ProvisioningProfile;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the global provisioning-profile catalog.
 *
 * The `provisioning_profiles.code` column is globally unique (see migration),
 * so like ServiceCatalogSeeder this is a shared, non-tenant catalog keyed on
 * the canonical code values. Because `tenant_id` is NOT NULL in the schema,
 * rows are attached to the primary demo tenant.
 */
class ProvisioningProfileSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'primenet-isp')->first();

        if (! $tenant) {
            $this->command->warn('ProvisioningProfileSeeder: no representative tenant found. Skipping.');
            return;
        }

        $profiles = [
            ['code' => 'pppoe',      'name' => 'PPPoE',          'description' => 'PPPoE connection provisioning',         'provisioning_rules' => ['auto_activate' => true, 'sla_hours' => 24]],
            ['code' => 'hotspot',    'name' => 'Hotspot',        'description' => 'Public/captive-portal hotspot provisioning', 'provisioning_rules' => ['auto_activate' => true, 'sla_hours' => 4]],
            ['code' => 'static_ip',  'name' => 'Static IP',      'description' => 'Static IP addressing provisioning',      'provisioning_rules' => ['auto_activate' => true, 'sla_hours' => 24]],
            ['code' => 'fiber',      'name' => 'Fiber',          'description' => 'Fiber-to-the-home provisioning',         'provisioning_rules' => ['auto_activate' => true, 'sla_hours' => 48]],
            ['code' => 'enterprise', 'name' => 'Enterprise',     'description' => 'Multi-step enterprise onboarding',       'provisioning_rules' => ['auto_activate' => false, 'sla_hours' => 48]],
            ['code' => 'dedicated',  'name' => 'Dedicated',      'description' => 'Dedicated leased-line provisioning',     'provisioning_rules' => ['auto_activate' => false, 'sla_hours' => 72]],
        ];

        $count = 0;
        foreach ($profiles as $profile) {
            ProvisioningProfile::updateOrCreate(
                ['code' => $profile['code']],
                array_merge($profile, ['tenant_id' => $tenant->id])
            );
            $count++;
        }

        $this->command->info('ProvisioningProfileSeeder: ' . $count . ' global provisioning profiles seeded.');
    }
}
