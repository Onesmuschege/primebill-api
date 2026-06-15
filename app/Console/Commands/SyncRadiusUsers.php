<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Console\Command;

class SyncRadiusUsers extends Command
{
    protected $signature = 'radius:sync-users {--account= : Sync a single account by ID}';

    protected $description = 'Synchronize active client accounts to FreeRADIUS';

    public function handle(RadiusAdapterInterface $radiusAdapter): int
    {
        if ($accountId = $this->option('account')) {
            $account = ClientAccount::with('plan')->find($accountId);

            if (!$account || !$account->plan) {
                $this->error('Account not found or has no plan.');

                return self::FAILURE;
            }

            $radiusAdapter->createUser([
                'username'   => $account->username,
                'password'   => 'requires-reprovision',
                'group'      => $account->plan->name,
                'rate_limit' => ($account->plan->speed_up ?? 512) . 'k/' . ($account->plan->speed_down ?? 1024) . 'k',
            ]);

            $this->info("Synced account {$account->username}.");

            return self::SUCCESS;
        }

        $ok = $radiusAdapter->syncUsers();

        if ($ok) {
            $this->info('RADIUS user sync completed.');

            return self::SUCCESS;
        }

        $this->error('RADIUS sync failed — verify RADIUS tables and connection.');

        return self::FAILURE;
    }
}
