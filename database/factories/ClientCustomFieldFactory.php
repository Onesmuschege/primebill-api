<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientCustomFieldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->word(),
            'label' => fake()->words(2, true),
            'type' => fake()->randomElement(['text', 'textarea', 'number', 'date', 'select', 'checkbox', 'url']),
            'options' => null,
            'is_required' => fake()->boolean(30),
            'is_visible_on_portal' => fake()->boolean(50),
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
