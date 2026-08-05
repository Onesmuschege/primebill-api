<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncRadiusUsers extends Command
{
    protected $signature = 'radius:sync-users {--account= : Sync a single account by ID}';

    protected $description = 'Synchronize active client accounts to FreeRADIUS';

    public function handle(RadiusAdapterInterface $radiusAdapter): int
    {
        // --- Single-account sync -------------------------------------------------
        if ($accountId = $this->option('account')) {
            $account = ClientAccount::with('plan')->find($accountId);

            if (!$account) {
                $this->error('Account not found.');

                return self::FAILURE;
            }

            if (!$account->plan) {
                $this->error('Account has no plan — cannot provision.');

                return self::FAILURE;
            }

            $ok = $radiusAdapter->syncUsersToAccount($account);

            if ($ok) {
                $this->info("Synced account {$account->username}.");

                return self::SUCCESS;
            }

            $this->error("Failed to sync account {$account->username}.");

            return self::FAILURE;
        }

        // --- Bulk sync, iterating tenants so the tenant global scope applies ----
        $tenants = Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found. Nothing to sync.');

            return self::SUCCESS;
        }

        $synced = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            app()->instance('currentTenant', $tenant);

            $accounts = ClientAccount::with('plan')
                ->whereIn('status', ['active'])
                ->get();

            foreach ($accounts as $account) {
                if (!$account->plan) {
                    continue;
                }

                try {
                    if ($radiusAdapter->syncUsersToAccount($account)) {
                        $synced++;
                    } else {
                        $failed++;
                        Log::warning("radius:sync-users failed for {$account->username}", ['account_id' => $account->id]);
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error("radius:sync-users exception for {$account->username}: {$e->getMessage()}", [
                        'account_id' => $account->id,
                    ]);
                }
            }
        }

        app()->forgetInstance('currentTenant');

        $this->info("RADIUS sync completed: {$synced} synced, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
