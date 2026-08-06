<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->optional()->sentence(),
            'billing_cycle' => 'monthly',
            'price' => $this->faker->randomFloat(2, 0, 500),
            'annual_price' => $this->faker->optional()->randomFloat(2, 0, 5000),
            'is_active' => true,
            'is_trial_available' => true,
            'trial_days' => 14,
            'grace_days' => 7,
            'features' => ['basic_billing', 'email_support'],
            'max_clients' => 500,
            'max_users' => 5,
            'max_routers' => 3,
            'storage_quota_gb' => 10,
            'api_calls_per_month' => 10000,
            'sort_order' => 0,
        ];
    }

    public function starter(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'starter',
            'name' => 'Starter',
            'price' => 0,
            'annual_price' => 0,
            'max_clients' => 500,
            'max_users' => 5,
            'max_routers' => 3,
        ]);
    }

    public function professional(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'professional',
            'name' => 'Professional',
            'price' => 99,
            'annual_price' => 990,
            'max_clients' => 2500,
            'max_users' => 15,
            'max_routers' => 10,
        ]);
    }

    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'enterprise',
            'name' => 'Enterprise',
            'price' => 299,
            'annual_price' => 2990,
            'max_clients' => 10000,
            'max_users' => 50,
            'max_routers' => 50,
        ]);
    }
}
