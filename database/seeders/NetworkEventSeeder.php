<?php

namespace Database\Seeders;

use App\Models\NetworkEvent;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class NetworkEventSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $events = [
                ['type' => 'outage', 'severity' => 'critical', 'description' => 'Link failure at North POP', 'occurred_at' => Carbon::now()->subDays(2)],
                ['type' => 'maintenance', 'severity' => 'info', 'description' => 'Scheduled maintenance on Core-Router-01', 'occurred_at' => Carbon::now()->subDays(5)],
                ['type' => 'congestion', 'severity' => 'warning', 'description' => 'High latency detected on WAN interface', 'occurred_at' => Carbon::now()->subDay()],
                ['type' => 'recovery', 'severity' => 'info', 'description' => 'Service restored after maintenance', 'occurred_at' => Carbon::now()->subDays(5)->addHours(2)],
            ];

            foreach ($events as $event) {
                NetworkEvent::create(array_merge($event, ['tenant_id' => $tenant->id]));
            }

            $this->command->line("  [{$tenant->slug}] " . count($events) . " network events seeded.");
        });

        $this->command->info('NetworkEventSeeder: complete.');
    }
}