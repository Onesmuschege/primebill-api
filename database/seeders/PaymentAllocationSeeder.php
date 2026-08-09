<?php

namespace Database\Seeders;

use App\Models\PaymentAllocation;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class PaymentAllocationSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $this->command->line("  [{$tenant->slug}] PaymentAllocationSeeder skipped (allocations created with payments).");
        });

        $this->command->info('PaymentAllocationSeeder: complete.');
    }
}
