<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => Client::factory(),
            'amount' => $this->faker->randomFloat(2, 100, 10000),
            'method' => $this->faker->randomElement(['mpesa', 'cash', 'bank']),
            'status' => $this->faker->randomElement(['completed', 'pending', 'failed']),
            'reference' => $this->faker->unique()->uuid(),
            'mpesa_code' => $this->faker->optional()->numerify('##########'),
            'idempotency_key' => $this->faker->unique()->uuid(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }

    public function mpesa(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'mpesa',
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'cash',
        ]);
    }

    public function bank(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'bank',
        ]);
    }
}
