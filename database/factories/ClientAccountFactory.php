<?php

namespace Database\Factories;

use App\Models\ClientAccount;
use App\Models\Client;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientAccountFactory extends Factory
{
    protected $model = ClientAccount::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'plan_id' => Plan::factory(),
            'username' => $this->faker->unique()->userName(),
            'password' => bcrypt('password'),
            'type' => $this->faker->randomElement(['prepaid', 'postpaid']),
            'status' => $this->faker->randomElement(['active', 'inactive', 'suspended', 'overdue']),
            'mac_address' => $this->faker->optional()->macAddress(),
            'ip_address' => $this->faker->optional()->ipv4(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'overdue',
        ]);
    }

    public function prepaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'prepaid',
        ]);
    }

    public function postpaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'postpaid',
        ]);
    }
}
