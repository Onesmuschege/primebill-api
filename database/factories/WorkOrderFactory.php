<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'work_order_number' => fake()->unique()->regexify('WO-[0-9]+-[0-9]{8}-[A-Z0-9]{6}'),
            'client_id' => Client::factory(),
            'type' => fake()->randomElement(['installation', 'repair', 'relocation', 'maintenance', 'survey']),
            'status' => fake()->randomElement(['scheduled', 'dispatched', 'in_progress', 'completed', 'cancelled']),
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'description' => fake()->paragraph(),
            'notes' => fake()->optional()->paragraph(),
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+1 week'),
            'started_at' => null,
            'completed_at' => null,
            'assigned_to' => null,
            'created_by' => User::factory(),
            'photos' => null,
            'customer_signature' => null,
            'completion_notes' => null,
            'completion_latitude' => null,
            'completion_longitude' => null,
        ];
    }
}
