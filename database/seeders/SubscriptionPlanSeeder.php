<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Seeds the global SaaS subscription catalog (platform-level plans).
 *
 * These are platform-owned (GLOBAL), NOT tenant-owned — they represent the
 * tiers an ISP pays PrimeBill for, and are shared across every tenant.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug'              => 'starter',
                'name'              => 'Starter',
                'description'       => 'For small ISPs getting started. Basic billing and reports.',
                'billing_cycle'     => 'monthly',
                'price'             => 0,
                'annual_price'      => 0,
                'is_active'         => true,
                'is_trial_available'=> true,
                'trial_days'        => 14,
                'grace_days'        => 7,
                'features'          => ['basic_billing', 'basic_reports', 'email_support'],
                'max_clients'       => 500,
                'max_users'         => 5,
                'max_routers'       => 3,
                'storage_quota_gb'  => 10,
                'api_calls_per_month' => 10000,
                'sort_order'        => 1,
            ],
            [
                'slug'              => 'professional',
                'name'              => 'Professional',
                'description'       => 'For growing ISPs. Advanced reports, API access and SMS.',
                'billing_cycle'     => 'monthly',
                'price'             => 99,
                'annual_price'      => 990,
                'is_active'         => true,
                'is_trial_available'=> true,
                'trial_days'        => 14,
                'grace_days'        => 7,
                'features'          => ['basic_billing', 'advanced_reports', 'api_access', 'priority_support', 'sms'],
                'max_clients'       => 2500,
                'max_users'         => 15,
                'max_routers'       => 10,
                'storage_quota_gb'  => 50,
                'api_calls_per_month' => 50000,
                'sort_order'        => 2,
            ],
            [
                'slug'              => 'enterprise',
                'name'              => 'Enterprise',
                'description'       => 'Full-featured platform for large ISPs and MSOs.',
                'billing_cycle'     => 'monthly',
                'price'             => 299,
                'annual_price'      => 2990,
                'is_active'         => true,
                'is_trial_available'=> false,
                'trial_days'        => 0,
                'grace_days'        => 7,
                'features'          => ['basic_billing', 'advanced_reports', 'api_access', 'priority_support', 'sms', 'custom_domain', 'white_label'],
                'max_clients'       => 10000,
                'max_users'         => 50,
                'max_routers'       => 50,
                'storage_quota_gb'  => 200,
                'api_calls_per_month' => 200000,
                'sort_order'        => 3,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }

        $this->command->info('SubscriptionPlanSeeder: 3 SaaS subscription plans seeded.');
    }
}
