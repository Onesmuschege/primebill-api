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
                'name'           => 'Home Basic 2Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 2048,
                'speed_down'     => 2048,
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
                'name'           => 'Home Standard 5Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 5120,
                'speed_down'     => 5120,
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
                'name'           => 'Home Premium 10Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 10240,
                'speed_down'     => 10240,
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
                'name'           => 'Business 20Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 20480,
                'speed_down'     => 20480,
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
                'name'           => 'Business Pro 50Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 51200,
                'speed_down'     => 51200,
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
                'name'           => 'Home Family 3Mbps',
                'type'           => 'pppoe',
                'speed_up'       => 3072,
                'speed_down'     => 3072,
                'burst_up'       => 6144,
                'burst_down'     => 6144,
                'fup_limit'      => 30,
                'fup_speed_up'   => 768,
                'fup_speed_down' => 768,
                'validity_days'  => 30,
                'price'          => 2000.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],

            [
                'name'           => 'Hotspot 1 Hour',
                'type'           => 'hotspot',
                'speed_up'       => 2048,
                'speed_down'     => 2048,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 1,
                'fup_speed_up'   => 512,
                'fup_speed_down' => 512,
                'validity_days'  => 1,
                'price'          => 50.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Hotspot Daily 2Mbps',
                'type'           => 'hotspot',
                'speed_up'       => 2048,
                'speed_down'     => 2048,
                'burst_up'       => 3072,
                'burst_down'     => 3072,
                'fup_limit'      => 3,
                'fup_speed_up'   => 512,
                'fup_speed_down' => 512,
                'validity_days'  => 1,
                'price'          => 100.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],

            // ── NEW: rounds out the hotspot lineup for the captive portal ──
            [
                'name'           => 'Hotspot 3 Hours',
                'type'           => 'hotspot',
                'speed_up'       => 2048,
                'speed_down'     => 2048,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 3,
                'fup_speed_up'   => 512,
                'fup_speed_down' => 512,
                'validity_days'  => 1,
                'price'          => 100.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Hotspot Weekend Pass',
                'type'           => 'hotspot',
                'speed_up'       => 3072,
                'speed_down'     => 3072,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 8,
                'fup_speed_up'   => 768,
                'fup_speed_down' => 768,
                'validity_days'  => 2,
                'price'          => 250.00,
                'router_id'      => $routerId,
                'is_active'      => true,
            ],
            [
                'name'           => 'Hotspot Weekly',
                'type'           => 'hotspot',
                'speed_up'       => 3072,
                'speed_down'     => 3072,
                'burst_up'       => 4096,
                'burst_down'     => 4096,
                'fup_limit'      => 25,
                'fup_speed_up'   => 768,
                'fup_speed_down' => 768,
                'validity_days'  => 7,
                'price'          => 500.00,
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