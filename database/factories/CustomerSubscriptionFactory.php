<?php

namespace Database\Factories;

use App\Models\CustomerSubscription;
use App\Models\Client;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerSubscriptionFactory extends Factory
{
    protected $model = CustomerSubscription::class;

    public function definition(): array
    {
        return [
            'tenant_id' => \App\Models\Tenant::factory(),
            'client_id' => Client::factory(),
            'product_id' => \App\Models\Product::factory(),
            'plan_id' => Plan::factory(),
            'name' => fake()->words(3, true),
            'status' => fake()->randomElement(['pending', 'active', 'suspended', 'cancelled', 'expired', 'completed']),
            'type' => fake()->randomElement(['new', 'upgrade', 'downgrade', 'renewal', 'addon']),
            'price' => fake()->randomFloat(2, 10, 500),
            'discount' => fake()->randomFloat(2, 0, 50),
            'tax' => fake()->randomFloat(2, 0, 50),
            'total' => fake()->randomFloat(2, 10, 500),
            'starts_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'ends_at' => fake()->dateTimeBetween('now', '+1 year'),
            'activated_at' => fake()->optional()->dateTime(),
            'suspended_at' => fake()->optional()->dateTime(),
            'cancelled_at' => fake()->optional()->dateTime(),
            'completed_at' => fake()->optional()->dateTime(),
            'contract_period_months' => fake()->optional()->numberBetween(1, 24),
            'auto_renew' => fake()->boolean(70),
            'prorated' => fake()->boolean(30),
            'notes' => fake()->optional()->paragraph(),
            'metadata' => fake()->optional()->words(5, true),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
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

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'ends_at' => now()->subDays(5),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'ends_at' => now()->addDays(3),
        ]);
    }
}
