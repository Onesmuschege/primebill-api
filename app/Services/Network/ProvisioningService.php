<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\MikrotikSyncLog;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Support\Facades\Log;

class ProvisioningService
{
    public function __construct(
        protected RouterAdapterInterface $routerAdapter,
        protected RadiusAdapterInterface $radiusAdapter
    ) {}

    public function provisionAccount(ClientAccount $account, string $plainPassword): bool
    {
        $account->loadMissing('plan');

        if (!$account->plan) {
            $this->log("Skipped provisioning for {$account->username}: no plan assigned");

            return false;
        }

        $payload = [
            'username'  => $account->username,
            'password'  => $plainPassword,
            'profile'   => $account->plan->name,
            'plan_type' => $account->plan->type,
            'router_id' => $account->plan->router_id,
        ];

        $routerOk = $this->routerAdapter->createUser($payload);
        $radiusOk = $this->radiusAdapter->createUser([
            'username'   => $account->username,
            'password'   => $plainPassword,
            'group'      => $account->plan->name,
            'rate_limit' => $this->buildRateLimit($account),
        ]);

        $success = $routerOk && $radiusOk;

        $this->log(sprintf(
            'Provisioned account %s (router=%s, radius=%s)',
            $account->username,
            $routerOk ? 'ok' : 'fail',
            $radiusOk ? 'ok' : 'fail'
        ));

        if (!$success) {
            Log::warning('ProvisioningService: partial failure', [
                'account_id' => $account->id,
                'router_ok'  => $routerOk,
                'radius_ok'  => $radiusOk,
            ]);
        }

        return $success;
    }

    public function suspendAccount(ClientAccount $account): bool
    {
        $routerOk = $this->routerAdapter->suspendUser($account->username);
        $radiusOk = $this->radiusAdapter->suspendUser($account->username);

        $this->log("Suspended account {$account->username} (router={$routerOk}, radius={$radiusOk})");

        return $routerOk && $radiusOk;
    }

    public function activateAccount(ClientAccount $account): bool
    {
        $routerOk = $this->routerAdapter->unsuspendUser($account->username);
        $radiusOk = $this->radiusAdapter->unsuspendUser($account->username);

        $this->log("Activated account {$account->username} (router={$routerOk}, radius={$radiusOk})");

        return $routerOk && $radiusOk;
    }

    public function deprovisionAccount(ClientAccount $account, ?int $routerId = null): bool
    {
        $routerOk = $this->routerAdapter->deleteUser($account->username);
        $radiusOk = $this->radiusAdapter->deleteUser($account->username);

        $this->log("Deprovisioned account {$account->username}");

        return $routerOk && $radiusOk;
    }

    public function deprovisionUsername(string $username): bool
    {
        $routerOk = $this->routerAdapter->deleteUser($username);
        $radiusOk = $this->radiusAdapter->deleteUser($username);

        $this->log("Deprovisioned username {$username}");

        return $routerOk && $radiusOk;
    }

    public function suspendClientAccounts(int $clientId): void
    {
        ClientAccount::where('client_id', $clientId)
            ->where('status', 'active')
            ->each(fn (ClientAccount $account) => $this->suspendAccount($account));
    }

    public function activateClientAccounts(int $clientId): void
    {
        ClientAccount::where('client_id', $clientId)
            ->where('status', 'suspended')
            ->each(fn (ClientAccount $account) => $this->activateAccount($account));
    }

    protected function buildRateLimit(ClientAccount $account): string
    {
        $plan = $account->plan;
        $down = $plan->speed_down ?? 1024;
        $up   = $plan->speed_up ?? 512;

        return "{$up}k/{$down}k";
    }

    protected function log(string $message): void
    {
        MikrotikSyncLog::create(['log_message' => $message]);
        Log::info('ProvisioningService: ' . $message);
    }
}
