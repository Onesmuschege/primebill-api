<?php

namespace Database\Seeders;

use App\Models\Ont;
use App\Models\OntSignalHistory;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds optical signal history for the tenant's ONTs. Uses the actual
 * ontology columns (rx_power / tx_power / temperature / voltage /
 * bias_current). Idempotent via a per-tenant guard.
 */
class OntSignalHistorySeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $onts = Ont::where('tenant_id', $tenant->id)->get();

            if ($onts->isEmpty()) {
                $this->command->warn("OntSignalHistorySeeder [{$tenant->slug}]: No ONT found. Skipping.");
                return;
            }

            if (OntSignalHistory::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] ONT signal history already present — skipped.");
                return;
            }

            $created = 0;
            foreach ($onts->take(10) as $index => $ont) {
                // 6 hourly samples per ONT.
                for ($h = 0; $h < 6; $h++) {
                    $rx = -24 + (($index * 3 + $h * 7) % 8); // roughly -24..-16 dBm
                    OntSignalHistory::create([
                        'tenant_id' => $tenant->id,
                        'ont_id' => $ont->id,
                        'rx_power' => round($rx, 2),
                        'tx_power' => round(1.5 + (($index + $h) % 5) / 10, 2),
                        'temperature' => round(38 + (($index * 11 + $h) % 12), 2),
                        'voltage' => round(3.30 + (($index + $h) % 10) / 100, 2),
                        'bias_current' => round(22 + (($index * 7 + $h) % 18), 2),
                        'notes' => 'Seeded optical sample ' . ($h + 1),
                        'metadata' => ['ont_serial' => $ont->serial],
                        'collected_by' => null,
                        'created_at' => Carbon::now()->subHours($h * 5)->subMinutes($index),
                        'updated_at' => Carbon::now()->subHours($h * 5)->subMinutes($index),
                    ]);
                    $created++;
                }
            }

            $this->command->line("  [{$tenant->slug}] {$created} ONT signal history rows seeded.");
        });

        $this->command->info('OntSignalHistorySeeder: complete.');
    }
}
