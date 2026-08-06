<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => Client::factory(),
            'invoice_number' => 'INV-' . $this->faker->unique()->numerify('#######'),
            'amount' => $this->faker->randomFloat(2, 500, 50000),
            'total' => $this->faker->randomFloat(2, 500, 50000),
            'status' => $this->faker->randomElement(['paid', 'pending', 'overdue', 'cancelled']),
            'due_date' => $this->faker->dateTimeBetween('now', '+30 days'),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'overdue',
            'due_date' => $this->faker->dateTimeBetween('-30 days', 'now'),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
