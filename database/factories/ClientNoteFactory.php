<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => Client::factory(),
            'created_by' => User::factory(),
            'note' => fake()->paragraph(),
            'type' => fake()->randomElement(['general', 'call', 'meeting', 'support']),
            'priority' => fake()->randomElement(['low', 'normal', 'high']),
            'is_pinned' => false,
            'pinned_at' => null,
        ];
    }
}
