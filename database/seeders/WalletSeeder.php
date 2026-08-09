<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Wallet;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class WalletSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            // Wallets are created per client; we skip here and let TenantUserSeeder or ClientSeeder handle it.
            $this->command->line("  [{$tenant->slug}] WalletSeeder skipped (wallets created with clients).");
        });

        $this->command->info('WalletSeeder: complete.');
    }
}
