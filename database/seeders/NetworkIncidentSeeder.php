<?php

namespace Database\Seeders;

use App\Models\NetworkIncident;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class NetworkIncidentSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            NetworkIncident::create([
                'tenant_id' => $tenant->id,
                'title' => 'Link degradation at North POP',
                'severity' => 'major',
                'status' => 'resolved',
                'started_at' => Carbon::now()->subDays(3),
                'resolved_at' => Carbon::now()->subDays(2),
            ]);

            $this->command->line("  [{$tenant->slug}] Network incident seeded.");
        });

        $this->command->info('NetworkIncidentSeeder: complete.');
    }
}
