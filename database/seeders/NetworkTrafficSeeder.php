<?php

namespace Database\Seeders;

use App\Models\Router;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Seeds 7 days of hourly network traffic for the demo router.
 * Three interfaces: WAN, LAN, WLAN.
 * Traffic peaks realistically 7am–11pm (Kenyan usage pattern).
 * Bulk-inserted in chunks for performance.
 */
class NetworkTrafficSeeder extends Seeder
{
    public function run(): void
    {
        $router = Router::where('name', 'Core-Router-01')->first();

        if (! $router) {
            $this->command->warn('NetworkTrafficSeeder: Router not found. Skipping.');
            return;
        }

        // Skip if data already exists
        $existing = DB::table('network_traffic')->where('router_id', $router->id)->count();
        if ($existing > 0) {
            $this->command->info("NetworkTrafficSeeder: Traffic data already exists ({$existing} records). Skipping.");
            return;
        }

        $interfaces = ['ether1-WAN', 'ether2-LAN', 'wlan1'];
        $records    = [];

        foreach ($interfaces as $interface) {
            for ($daysAgo = 7; $daysAgo >= 0; $daysAgo--) {
                for ($hour = 0; $hour < 24; $hour++) {
                    $recordedAt = Carbon::now()
                        ->subDays($daysAgo)
                        ->setHour($hour)
                        ->setMinute(0)
                        ->setSecond(0);

                    // Realistic traffic curve:
                    // Night (0–6): low     ~2–5 Mbps
                    // Morning (7–9): rising  ~10–30 Mbps
                    // Day (10–17): steady  ~20–50 Mbps
                    // Evening (18–22): peak   ~40–100 Mbps
                    // Late (23): falling ~10–25 Mbps
                    $multiplier = $this->trafficMultiplier($hour);

                    $records[] = [
                        'router_id'   => $router->id,
                        'tx_bytes'    => rand(500_000, 2_000_000) * $multiplier,
                        'rx_bytes'    => rand(2_000_000, 10_000_000) * $multiplier,
                        'interface'   => $interface,
                        'recorded_at' => $recordedAt->toDateTimeString(),
                    ];
                }
            }
        }

        // Bulk insert in chunks of 200
        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('network_traffic')->insert($chunk);
        }

        $total = count($records);
        $this->command->info("NetworkTrafficSeeder: {$total} traffic records seeded.");
    }

    private function trafficMultiplier(int $hour): int
    {
        return match (true) {
            $hour >= 18 && $hour <= 22 => rand(8, 15),  // Evening peak
            $hour >= 10 && $hour <= 17 => rand(4, 8),   // Business hours
            $hour >= 7  && $hour <= 9  => rand(3, 6),   // Morning ramp
            $hour === 23               => rand(2, 4),   // Late night
            default                    => rand(1, 2),   // Dead hours
        };
    }
}
