<?php

namespace Database\Seeders;

use App\Models\Ont;
use App\Models\OntEvent;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds ONT lifecycle events (online, offline, los, signal_degraded,
 * registration, dying_gasp) for the tenant's ONTs. Idempotent guard.
 */
class OntEventSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $onts = Ont::where('tenant_id', $tenant->id)->get();

            if ($onts->isEmpty()) {
                $this->command->warn("OntEventSeeder [{$tenant->slug}]: No ONT found. Skipping.");
                return;
            }

            if (OntEvent::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] ONT events already present — skipped.");
                return;
            }

            $events = [
                ['event' => 'registration', 'severity' => 'info', 'description' => 'ONT registered to PON port'],
                ['event' => 'online', 'severity' => 'info', 'description' => 'ONT came online'],
                ['event' => 'signal_degraded', 'severity' => 'warning', 'description' => 'Received optical power below threshold'],
                ['event' => 'offline', 'severity' => 'warning', 'description' => 'ONT lost connectivity'],
                ['event' => 'los', 'severity' => 'critical', 'description' => 'Loss of signal detected'],
                ['event' => 'dying_gasp', 'severity' => 'error', 'description' => 'ONT power loss detected'],
            ];

            $created = 0;
            foreach ($onts->take(10) as $index => $ont) {
                foreach ($events as $i => $ev) {
                    OntEvent::create([
                        'tenant_id' => $tenant->id,
                        'ont_id' => $ont->id,
                        'event' => $ev['event'],
                        'severity' => $ev['severity'],
                        'description' => $ev['description'] . ' (' . $ont->serial . ')',
                        'metadata' => ['ont_serial' => $ont->serial, 'pon_port' => $ont->pon_port_id],
                        'created_by' => null,
                        'created_at' => Carbon::now()->subDays($i + 1)->subMinutes($index * 13),
                        'updated_at' => Carbon::now()->subDays($i + 1)->subMinutes($index * 13),
                    ]);
                    $created++;
                }
            }

            $this->command->line("  [{$tenant->slug}] {$created} ONT events seeded.");
        });

        $this->command->info('OntEventSeeder: complete.');
    }
}
