<?php

namespace Database\Seeders;

use App\Models\ServiceAddon;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the global service-addon catalog.
 *
 * The `service_addons.code` column is globally unique (see migration), so like
 * ServiceCatalogSeeder this is a shared, non-tenant catalog keyed on the
 * canonical code values. Because `tenant_id` is NOT NULL in the schema, rows
 * are attached to the primary demo tenant.
 */
class ServiceAddonSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'primenet-isp')->first();

        if (! $tenant) {
            $this->command->warn('ServiceAddonSeeder: no representative tenant found. Skipping.');
            return;
        }

        $addons = [
            ['code' => 'static_ip',         'name' => 'Static IP',         'category' => 'ip',        'description' => 'Dedicated static IP address',              'price' => 500.00,  'billing_type' => 'monthly'],
            ['code' => 'extra_bandwidth',   'name' => 'Extra Bandwidth',    'category' => 'bandwidth', 'description' => 'Additional bandwidth allowance',            'price' => 800.00,  'billing_type' => 'monthly'],
            ['code' => 'public_ip',         'name' => 'Public IP',          'category' => 'ip',        'description' => 'Additional public IPv4 address',            'price' => 300.00,  'billing_type' => 'monthly'],
            ['code' => 'managed_router',    'name' => 'Managed Router',     'category' => 'hardware',  'description' => 'Routers management and maintenance',        'price' => 1500.00, 'billing_type' => 'monthly'],
            ['code' => 'backup_link',       'name' => 'Backup Link',        'category' => 'reliability','description' => 'Redundant backup connectivity link',        'price' => 2500.00, 'billing_type' => 'monthly'],
            ['code' => 'premium_support',   'name' => 'Premium Support',    'category' => 'support',   'description' => '24/7 premium technical support',             'price' => 1000.00, 'billing_type' => 'monthly'],
        ];

        $count = 0;
        foreach ($addons as $addon) {
            ServiceAddon::updateOrCreate(
                ['code' => $addon['code']],
                array_merge($addon, ['tenant_id' => $tenant->id])
            );
            $count++;
        }

        $this->command->info('ServiceAddonSeeder: ' . $count . ' global service addons seeded.');
    }
}
