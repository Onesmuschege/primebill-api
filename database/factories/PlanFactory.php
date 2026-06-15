<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name'          => fake()->words(2, true),
            'type'          => 'pppoe',
            'speed_up'      => 5120,
            'speed_down'    => 10240,
            'validity_days' => 30,
            'price'         => fake()->randomFloat(2, 500, 5000),
            'is_active'     => true,
        ];
    }
}
