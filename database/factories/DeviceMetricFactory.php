<?php

namespace Database\Factories;

use App\Models\DeviceMetric;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceMetricFactory extends Factory
{
    protected $model = DeviceMetric::class;

    public function definition(): array
    {
        return [
            'tenant_id'   => Tenant::factory(),
            'device_id'   => Router::factory(),
            'metric_type' => $this->faker->randomElement(['cpu', 'ram', 'temp', 'traffic', 'interface_util', 'errors', 'drops', 'uptime', 'latency']),
            'value'       => $this->faker->randomFloat(2, 0, 100),
            'interface'   => $this->faker->randomElement(['ether1', 'ether2', 'wlan1', null]),
'unit'        => $this->faker->randomElement(['%', 'ms', 'MB', null]),
            'recorded_at' => now(),
        ];
    }
}
