<?php

namespace Database\Factories;

use App\Models\NetworkLink;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class NetworkLinkFactory extends Factory
{
    protected $model = NetworkLink::class;

    public function definition(): array
    {
        return [
            'tenant_id'   => Tenant::factory(),
            'device_a_id' => Router::factory(),
            'device_b_id' => Router::factory(),
            'interface_a' => $this->faker->randomElement(['ether1', 'ether2', 'sfp1']),
            'interface_b' => $this->faker->randomElement(['ether1', 'ether2', 'sfp1']),
            'media'       => $this->faker->randomElement(['fiber', 'copper', 'wireless']),
            'status'      => $this->faker->randomElement(['up', 'down', 'degraded']),
            'description' => $this->faker->optional()->sentence(),
        ];
    }

    public function up(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'up',
        ]);
    }
}
