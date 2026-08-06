<?php

namespace Database\Factories;

use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class RouterFactory extends Factory
{
    protected $model = Router::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->company() . ' Router',
            'ip_address' => $this->faker->ipv4(),
            'username' => $this->faker->userName(),
            'password' => bcrypt('password'),
            'port' => $this->faker->numberBetween(8000, 9000),
            'location' => $this->faker->city(),
            'status' => $this->faker->randomElement(['online', 'offline']),
            'type' => $this->faker->randomElement(['mikrotik', 'ubiquiti', 'cisco']),
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'online',
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'offline',
        ]);
    }
}
