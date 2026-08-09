<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class LeadSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $this->command->line("  [{$tenant->slug}] LeadSeeder skipped (placeholder).");
        });

        $this->command->info('LeadSeeder: complete.');
    }
}
