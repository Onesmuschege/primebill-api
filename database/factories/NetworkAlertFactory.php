<?php

namespace Database\Factories;

use App\Models\NetworkAlert;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class NetworkAlertFactory extends Factory
{
    protected $model = NetworkAlert::class;

    public function definition(): array
    {
        return [
            'tenant_id'   => Tenant::factory(),
            'device_id'   => Router::factory(),
            'alert_type'  => $this->faker->randomElement(['device_offline', 'interface_down', 'high_cpu', 'high_ram', 'high_latency', 'high_util', 'health_failure']),
            'severity'    => $this->faker->randomElement(['info', 'warning', 'critical']),
            'message'     => $this->faker->sentence(),
            'status'      => 'open',
            'metric_value'=> $this->faker->randomFloat(2, 0, 100),
            'threshold'   => $this->faker->randomFloat(2, 80, 100),
            'interface'   => $this->faker->randomElement(['ether1', 'ether2', null]),
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'resolved_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'     => 'resolved',
            'resolved_at'=> now(),
        ]);
    }
}
