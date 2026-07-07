<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Router;
use Illuminate\Database\Seeder;

/**
 * Realistic Kenyan ISP plans — PPPoE and Hotspot.
 * Prices in KES. Speeds in Kbps.
 * All plans linked to the demo router seeded by RouterSeeder.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $routerId = Router::where('name', 'Core-Router-01')->value('id');

        $plans = [
            [
                'name'           => 'Home Basic 10Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 10240,
                'speed_down'     => 10240,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 20,
                'fup_speed_up'   => 512,
                'fup_speed_down' => 512,
                'validity_days'  => 30,
                'price'          => 1500.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Home Standard 30Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 30720,
                'speed_down'     => 30720,
                'burst_up'       => 8192,
                'burst_down'     => 8192,
                'fup_limit'      => 50,
                'fup_speed_up'   => 1024,
                'fup_speed_down' => 1024,
                'validity_days'  => 30,
                'price'          => 2500.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Home Premium 50Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 51200,
                'speed_down'     => 51200,
                'burst_up'       => 15360,
                'burst_down'     => 15360,
                'fup_limit'      => 100,
                'fup_speed_up'   => 2048,
                'fup_speed_down' => 2048,
                'validity_days'  => 30,
                'price'          => 4500.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Business 100Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 102400,
                'speed_down'     => 102400,
                'burst_up'       => 30720,
                'burst_down'     => 30720,
                'fup_limit'      => null,
                'fup_speed_up'   => null,
                'fup_speed_down' => null,
                'validity_days'  => 30,
                'price'          => 8000.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Business Pro 150Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 153600,
                'speed_down'     => 153600,
                'burst_up'       => 65536,
                'burst_down'     => 65536,
                'fup_limit'      => null,
                'fup_speed_up'   => null,
                'fup_speed_down' => null,
                'validity_days'  => 30,
                'price'          => 15000.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],

            // ── NEW: Family tier fills the gap between Basic and Standard ──
            [
                'name'           => 'Enterprise 300Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 307200,
                'speed_down'     => 307200,
                'burst_up'       => 92160,
                'burst_down'     => 92160,
                'fup_limit'      => 30,
                'fup_speed_up'   => 12288,
                'fup_speed_down' => 12288,
                'validity_days'  => 30,
                'price'          => 20000.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],

            [
                'name'           => 'Hotspot 1 Hour',
                'type'           => 'hotspot',
                'speed_up'       => 10240,
                'speed_down'     => 10240,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 1,
                'fup_speed_up'   => 512,
                'fup_speed_down' => 512,
                'validity_days'  => 1,
                'price'          => 10.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Hotspot 2 Hours',
                'type'           => 'hotspot',
                'speed_up'       => 10240,
                'speed_down'     => 10240,
                'burst_up'       => 3072,
                'burst_down'     => 3072,
                'fup_limit'      => 3,
                'fup_speed_up'   => 512,
                'fup_speed_down' => 512,
                'validity_days'  => 1,
                'price'          => 15.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],

            // ── NEW: rounds out the hotspot lineup for the captive portal ──
            [
                'name'           => 'Hotspot 5 Hours',
                'type'           => 'hotspot',
                'speed_up'       => 10240,
                'speed_down'     => 10240,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 3,
                'fup_speed_up'   => 512,
                'fup_speed_down' => 512,
                'validity_days'  => 1,
                'price'          => 20.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Hotspot 10 Hours',
                'type'           => 'hotspot',
                'speed_up'       => 10240,
                'speed_down'     => 10240,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 8,
                'fup_speed_up'   => 768,
                'fup_speed_down' => 768,
                'validity_days'  => 1,
                'price'          => 25.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Hotspot 24 Hours',
                'type'           => 'hotspot',
                'speed_up'       => 10240,
                'speed_down'     => 10240,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 25,
                'fup_speed_up'   => 768,
                'fup_speed_down' => 768,
                'validity_days'  => 1,
                'price'          => 30.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['name' => $plan['name']], $plan);
        }

        $this->command->info('PlanSeeder: ' . count($plans) . ' plans seeded.');
    }
}