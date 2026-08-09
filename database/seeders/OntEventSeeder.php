<?php

namespace Database\Seeders;

use App\Models\Ont;
use App\Models\OntEvent;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class OntEventSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $ont = Ont::where('tenant_id', $tenant->id)->first();

            if (! $ont) {
                $this->command->warn("OntEventSeeder [{$tenant->slug}]: No ONT found. Skipping.");
                return;
            }

            OntEvent::create([
                'tenant_id' => $tenant->id,
                'ont_id' => $ont->id,
                'event_type' => 'registration',
                'description' => 'ONT registered successfully',
                'occurred_at' => Carbon::now()->subDays(5),
            ]);

            $this->command->line("  [{$tenant->slug}] ONT event seeded.");
        });

        $this->command->info('OntEventSeeder: complete.');
    }
}
