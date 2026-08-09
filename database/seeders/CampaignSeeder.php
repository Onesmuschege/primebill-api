<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $this->command->line("  [{$tenant->slug}] CampaignSeeder skipped (placeholder).");
        });

        $this->command->info('CampaignSeeder: complete.');
    }
}
