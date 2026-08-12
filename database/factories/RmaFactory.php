<?php

namespace Database\Factories;

use App\Models\Rma;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RmaFactory extends Factory
{
    protected $model = Rma::class;

    public function definition(): array
    {
        return [
            'tenant_id'             => Tenant::factory(),
            'rma_number'            => 'RMA-' . $this->faker->unique()->randomNumber(6),
            'type'                  => $this->faker->randomElement(Rma::types()),
            'priority'              => Rma::PRIORITY_NORMAL,
            'status'                => Rma::STATUS_REQUESTED,
            'reason'                => $this->faker->sentence(),
            'priority'              => $this->faker->randomElement(Rma::priorities()),
            'requested_by'          => User::factory(),
            'requested_at'          => now(),
            'created_by'            => User::factory(),
            'updated_by'            => User::factory(),
        ];
    }
}
