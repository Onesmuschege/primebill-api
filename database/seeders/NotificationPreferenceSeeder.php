<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class NotificationPreferenceSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $this->command->line("  [{$tenant->slug}] NotificationPreferenceSeeder skipped (placeholder).");
        });

        $this->command->info('NotificationPreferenceSeeder: complete.');
    }
}
