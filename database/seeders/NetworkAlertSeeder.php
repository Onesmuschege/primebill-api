<?php

namespace Database\Seeders;

use App\Models\NetworkAlert;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class NetworkAlertSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            NetworkAlert::create([
                'tenant_id' => $tenant->id,
                'type' => 'threshold',
                'severity' => 'warning',
                'message' => 'High bandwidth utilization detected',
                'triggered_at' => Carbon::now()->subHours(3),
            ]);

            $this->command->line("  [{$tenant->slug}] Network alert seeded.");
        });

        $this->command->info('NetworkAlertSeeder: complete.');
    }
}
