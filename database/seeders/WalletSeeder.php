<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\Wallet;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds a prepaid wallet for every active/suspended client per tenant.
 * The wallet balance is a real monetary value (deposits via ledger) — for
 * seed data we set a deterministic starting balance so that prepaid
 * top-ups and credit are demonstrated on every tenant.
 */
class WalletSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $clients = Client::where('tenant_id', $tenant->id)
                ->whereIn('status', ['active', 'suspended', 'inactive'])
                ->get();

            if ($clients->isEmpty()) {
                $this->command->warn("WalletSeeder [{$tenant->slug}]: No clients found. Skipping.");
                return;
            }

            $created = 0;
            $updated = 0;

            foreach ($clients as $i => $client) {
                // Active clients get a prepaid balance; inactive/disabled
                // clients get a zero (or frozen) wallet.
                $isActive = $client->status === 'active';
                $balance  = $isActive ? round(1000 + ($i * 137), 2) : 0.00;
                $status   = $isActive ? 'active'
                         : ($client->status === 'suspended' ? 'frozen' : 'active');

                $wallet = Wallet::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'client_id' => $client->id],
                    [
                        'tenant_id' => $tenant->id,
                        'client_id' => $client->id,
                        'balance'   => $balance,
                        'currency'  => 'KES',
                        'status'    => $status,
                    ]
                );

                if ($wallet->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

                        $this->command->line("  [{$tenant->slug}] Wallets — created: {$created}, updated: {$updated}.");
        });

        $this->command->info('WalletSeeder: complete.');
    }
}
