<?php

namespace Database\Factories;

use App\Models\TenantSubscription;
use App\Models\Tenant;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantSubscriptionFactory extends Factory
{
    protected $model = TenantSubscription::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'name' => $this->faker->words(3, true),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'price' => $this->faker->randomFloat(2, 0, 500),
            'annual_price' => $this->faker->optional()->randomFloat(2, 0, 5000),
            'trial_ends_at' => null,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'cancels_at' => null,
            'suspended_at' => null,
            'cancelled_at' => null,
            'grace_days' => 7,
            'cancellation_reason' => null,
            'metadata' => [],
        ];
    }

    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'trial',
            'price' => 0,
            'trial_ends_at' => now()->addDays(14),
            'ends_at' => now()->addDays(14),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
            'suspended_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    public function annual(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_cycle' => 'annual',
            'ends_at' => now()->addYear(),
        ]);
    }
}
