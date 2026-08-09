<?php

namespace Database\Seeders;

use App\Models\Ont;
use App\Models\OntSignalHistory;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class OntSignalHistorySeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $ont = Ont::where('tenant_id', $tenant->id)->first();

            if (! $ont) {
                $this->command->warn("OntSignalHistorySeeder [{$tenant->slug}]: No ONT found. Skipping.");
                return;
            }

            OntSignalHistory::create([
                'tenant_id' => $tenant->id,
                'ont_id' => $ont->id,
                'rx_power_dbm' => -18.5,
                'tx_power_dbm' => 2.1,
                'recorded_at' => Carbon::now()->subHours(2),
            ]);

            $this->command->line("  [{$tenant->slug}] ONT signal history seeded.");
        });

        $this->command->info('OntSignalHistorySeeder: complete.');
    }
}
