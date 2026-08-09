<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class ReportScheduleSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $this->command->line("  [{$tenant->slug}] ReportScheduleSeeder skipped (placeholder).");
        });

        $this->command->info('ReportScheduleSeeder: complete.');
    }
}
