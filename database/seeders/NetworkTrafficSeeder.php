<?php

namespace Database\Seeders;

use App\Models\NetworkTraffic;
use App\Models\Router;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds network interface traffic samples per router with a realistic
 * diurnal load curve (low overnight, rising through the day, peaking in
 * the evening) plus jitter, at 20-minute resolution over the last 7 days.
 *
 * Previous version: only 60 samples per router spaced 170 minutes apart
 * over 7 days, so the dashboard's "day" view (which filters recorded_at
 * >= now()->subDay()) only ever caught ~8 of them — and those 8 came from
 * a linear `(i * 37) % 9000` sequence with tx/rx always locked to a fixed
 * 1:3 ratio, which is why the chart rendered as a near-flat, perfectly
 * straight line instead of a real traffic curve. It also capped itself to
 * the first 3 routers regardless of how many were actually online.
 *
 * Re-runnable: clears and rebuilds its own (now-7d .. now) window on every
 * run rather than skipping when data exists, so it isn't fooled by the
 * live network:poll-traffic scheduled job (every 5 min) leaving a trickle
 * of recent rows that would otherwise look like "fresh enough, skip".
 */
class NetworkTrafficSeeder extends Seeder
{
    use SeedsForTenant;

    private const INTERVAL_MINUTES = 20;
    private const DAYS_BACK = 7;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $routers = Router::where('tenant_id', $tenant->id)
                ->where('status', 'online')
                ->get();

            if ($routers->isEmpty()) {
                $this->command->warn("NetworkTrafficSeeder [{$tenant->slug}]: No online routers found. Skipping.");
                return;
            }

            // network:poll-traffic runs every 5 minutes (see routes/console.php)
            // and inserts real polled rows continuously. A "skip if any row is
            // recent" freshness check gets fooled by that trickle of live data
            // — it sees one recent timestamp and wrongly concludes the whole
            // 7-day window is fresh, even when the bulk of it is still the old
            // stale/synthetic pattern. Instead, this seeder owns a specific
            // historical window (now-7d .. now) and unconditionally clears +
            // rebuilds just that window every run — live-polled rows outside
            // that window (i.e. inserted after this run finishes) are never
            // touched.
            $rangeStart = Carbon::now()->subDays(self::DAYS_BACK)->startOfMinute();
            $rangeEnd   = Carbon::now();

            $cleared = NetworkTraffic::where('tenant_id', $tenant->id)
                ->whereBetween('recorded_at', [$rangeStart, $rangeEnd])
                ->delete();

            if ($cleared > 0) {
                $this->command->line("  [{$tenant->slug}] Cleared {$cleared} existing sample(s) in the seeding window before rebuilding.");
            }

            $interfaces = ['ether1', 'ether2', 'sfp1', 'bridge1'];
            $samples    = (int) (self::DAYS_BACK * 24 * 60 / self::INTERVAL_MINUTES);
            $start      = $rangeStart;
            $rows       = [];

            foreach ($routers as $rIndex => $router) {
                // Deterministic per-router randomness so re-running the seeder
                // (before the idempotency guard above kicks in) is reproducible.
                mt_srand(crc32($tenant->id . '-' . $router->id));

                // Give each router a distinct capacity band so multi-router
                // tenants don't all show identical curves.
                $capacity = 3000 + ($rIndex % 4) * 2000; // MB-equivalent ceiling per sample

                for ($i = 0; $i < $samples; $i++) {
                    $time = $start->copy()->addMinutes($i * self::INTERVAL_MINUTES);
                    $hour = (int) $time->format('H');
                    $interface = $interfaces[$i % count($interfaces)];

                    // Diurnal curve: trough ~04:00, peak ~20:00, floor so it
                    // never fully flatlines overnight.
                    $diurnal = 0.30 + 0.70 * max(0, sin((($hour - 6) / 24) * 2 * M_PI));
                    $weekendBoost = in_array($time->dayOfWeek, [0, 6], true) ? 1.15 : 1.0;
                    $noise = mt_rand(85, 115) / 100;

                    $rxBase = $capacity * $diurnal * $weekendBoost * $noise;
                    // Upload isn't a fixed multiple of download — vary it independently.
                    $txBase = $rxBase * (mt_rand(20, 45) / 100);

                    $rows[] = [
                        'tenant_id'   => $tenant->id,
                        'router_id'   => $router->id,
                        'tx_bytes'    => (int) round(max(50, $txBase) * 1024 * 1024),
                        'rx_bytes'    => (int) round(max(150, $rxBase) * 1024 * 1024),
                        'interface'   => $interface,
                        'recorded_at' => $time,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                NetworkTraffic::insert($chunk);
            }

            $this->command->line("  [{$tenant->slug}] " . count($rows) . ' network traffic samples seeded across ' . $routers->count() . ' router(s).');
        });

        $this->command->info('NetworkTrafficSeeder: complete.');
    }
}