<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            // Core
            'name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(),
            'status' => $this->faker->randomElement(['active', 'trial', 'suspended']),
            'plan' => $this->faker->randomElement(['starter', 'professional', 'enterprise']),
            'timezone' => 'Africa/Nairobi',
            'currency' => 'KES',

            // Company Details
            'contact_email' => $this->faker->companyEmail(),
            'contact_phone' => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
            'website' => $this->faker->optional()->url(),

            // Branding
            'primary_color' => '#2563eb',
            'secondary_color' => '#06b6d4',

            // Subscription
            'billing_cycle' => 'monthly',
            'plan_started_at' => now(),
            'plan_expires_at' => $this->faker->dateTimeBetween('now', '+1 year'),
            'trial_ends_at' => $this->faker->dateTimeBetween('now', '+14 days'),

            // Quotas
            'storage_quota_gb' => 10,
            'api_calls_per_month' => 10000,
            'max_clients' => 500,
            'max_users' => 5,
            'max_routers' => 3,

            // Feature Flags
            'feature_flags' => [],

            // Usage
            'api_calls_used' => 0,
            'storage_used_mb' => 0,

            // Billing
            'tax_rate' => 0,

            // Notes
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'trial_ends_at' => null,
        ]);
    }

    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'Test suspension',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
            'archived_at' => now(),
        ]);
    }

    public function starter(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'starter',
            'max_clients' => 500,
            'max_users' => 5,
            'max_routers' => 3,
            'storage_quota_gb' => 10,
            'api_calls_per_month' => 10000,
        ]);
    }

    public function professional(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'professional',
            'monthly_price' => 99,
            'max_clients' => 2500,
            'max_users' => 15,
            'max_routers' => 10,
            'storage_quota_gb' => 50,
            'api_calls_per_month' => 50000,
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'enterprise',
            'monthly_price' => 299,
            'max_clients' => 10000,
            'max_users' => 50,
            'max_routers' => 50,
            'storage_quota_gb' => 200,
            'api_calls_per_month' => 200000,
        ]);
    }

    public function withExpiredTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'trial',
            'trial_ends_at' => now()->subDays(1),
        ]);
    }

    public function withExpiredSubscription(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'plan_expires_at' => now()->subDays(1),
        ]);
    }
}
