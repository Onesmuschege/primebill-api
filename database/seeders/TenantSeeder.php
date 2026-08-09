<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the three development/demo ISP tenants.
 *
 * These are intentionally distinct SaaS tenants so platform-admins can test
 * tenant isolation, impersonation, per-tenant branding and differing feature
 * flags. The Platform Admin (is_platform_admin=true, tenant_id=null) is
 * deliberately NOT created here — it is promoted separately via:
 *
 *   php artisan platform:make-admin platform@primebill.co.ke
 */
class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = [
            [
                'slug'             => 'primenet-isp',
                'name'             => 'PrimeNet ISP',
                'status'           => 'active',
                'plan'             => 'enterprise',
                'timezone'         => 'Africa/Nairobi',
                'currency'         => 'KES',
                'contact_email'    => 'accounts@primenet.co.ke',
                'contact_phone'    => '+254700123001',
                'address'          => 'Kanduyi House, Bungoma Town, Bungoma County, Kenya',
                'website'          => 'https://primenet.co.ke',
                'primary_color'    => '#2563eb',
                'secondary_color'  => '#06b6d4',
                'billing_cycle'    => 'monthly',
                'monthly_price'    => 299.00,
                'storage_quota_gb' => 200,
                'api_calls_per_month' => 200000,
                'max_clients'      => 10000,
                'max_users'        => 50,
                'max_routers'      => 50,
                'feature_flags'    => ['fibre', 'noc', 'ipam', 'advanced_billing', 'inventory_engine', 'work_orders', 'customer_experience', 'security_admin', 'communications', 'radius_advanced'],
                'tax_name'         => 'VAT',
                'tax_number'       => 'P051234567A',
                'tax_rate'         => 16,
                'notes'            => 'Primary demo tenant — full-featured enterprise ISP.',
            ],
            [
                'slug'             => 'swiftlink-communications',
                'name'             => 'SwiftLink Communications',
                'status'           => 'active',
                'plan'             => 'professional',
                'timezone'         => 'Africa/Nairobi',
                'currency'         => 'KES',
                'contact_email'    => 'billing@swiftlink.co.ke',
                'contact_phone'    => '+254700123002',
                'address'          => 'Moi Avenue, Eldoret, Uasin Gishu County, Kenya',
                'website'          => 'https://swiftlink.co.ke',
                'primary_color'    => '#0ea5e9',
                'secondary_color'  => '#8b5cf6',
                'billing_cycle'    => 'monthly',
                'monthly_price'    => 99.00,
                'storage_quota_gb' => 50,
                'api_calls_per_month' => 50000,
                'max_clients'      => 2500,
                'max_users'        => 15,
                'max_routers'      => 10,
                'feature_flags'    => ['noc', 'ipam', 'advanced_billing', 'inventory_engine', 'communications'],
                'tax_name'         => 'VAT',
                'tax_number'       => 'P098765432B',
                'tax_rate'         => 16,
                'notes'            => 'Medium ISP — professional tier with NOC/IPAM and inventory.',
            ],
            [
                'slug'             => 'metrowave-internet',
                'name'             => 'MetroWave Internet',
                'status'           => 'trial',
                'plan'             => 'starter',
                'timezone'         => 'Africa/Nairobi',
                'currency'         => 'KES',
                'contact_email'    => 'hello@metrowave.net',
                'contact_phone'    => '+254700123003',
                'address'          => 'Kisumu Business Hub, Kisumu City, Kenya',
                'website'          => 'https://metrowave.net',
                'primary_color'    => '#10b981',
                'secondary_color'  => '#f59e0b',
                'billing_cycle'    => 'monthly',
                'monthly_price'    => 0,
                'storage_quota_gb' => 10,
                'api_calls_per_month' => 10000,
                'max_clients'      => 500,
                'max_users'        => 5,
                'max_routers'      => 3,
                'feature_flags'    => ['basic_billing', 'basic_reports'],
                'tax_name'         => 'VAT',
                'tax_number'       => 'P112233445C',
                'tax_rate'         => 16,
                'notes'            => 'Starter/trial tenant — limited feature surface for SaaS downgrade testing.',
                'trial_ends_at'    => now()->addDays(12),
                'plan_started_at'  => now()->subDays(2),
                'plan_expires_at'  => now()->addDays(12),
            ],
        ];

        foreach ($tenants as $data) {
            Tenant::updateOrCreate(
                ['slug' => $data['slug']],
                // Only include keys that exist on the extended tenants table.
                collect($data)->only([
                    'name', 'slug', 'status', 'plan', 'timezone', 'currency',
                    'contact_email', 'contact_phone', 'address', 'website',
                    'primary_color', 'secondary_color',
                    'billing_cycle', 'monthly_price',
                    'storage_quota_gb', 'api_calls_per_month', 'max_clients',
                    'max_users', 'max_routers', 'feature_flags',
                    'tax_name', 'tax_number', 'tax_rate',
                    'notes', 'trial_ends_at', 'plan_started_at', 'plan_expires_at',
                ])->all()
            );
        }

        $this->command->info('TenantSeeder: 3 tenants seeded (PrimeNet, SwiftLink, MetroWave).');
    }
}
