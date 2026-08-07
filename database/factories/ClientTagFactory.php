<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['VIP', 'Premium', 'Residential', 'Commercial', 'Enterprise']),
            'color' => fake()->hexColor(),
            'description' => fake()->sentence(),
        ];
    }
}
