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
            $this->log($account, 'provision', 'failed', null, null, 'No plan assigned', "Skipped provisioning for {$account->username}: no plan assigned");

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

        $status = $success ? 'success' : ($routerOk !== $radiusOk ? 'partial' : 'failed');

        $this->log(
            $account,
            'provision',
            $status,
            $routerOk,
            $radiusOk,
            $this->failureReason('provision', $routerOk, $radiusOk),
            sprintf(
                'Provisioned account %s (router=%s, radius=%s)',
                $account->username,
                $routerOk ? 'ok' : 'fail',
                $radiusOk ? 'ok' : 'fail'
            )
        );

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

        $success = $routerOk && $radiusOk;
        $this->log(
            $account,
            'suspend',
            $success ? 'success' : ($routerOk !== $radiusOk ? 'partial' : 'failed'),
            $routerOk,
            $radiusOk,
            $this->failureReason('suspend', $routerOk, $radiusOk),
            "Suspended account {$account->username} (router={$routerOk}, radius={$radiusOk})"
        );

        return $success;
    }

    public function activateAccount(ClientAccount $account): bool
    {
        $routerOk = $this->routerAdapter->unsuspendUser($account->username);
        $radiusOk = $this->radiusAdapter->unsuspendUser($account->username);

        $success = $routerOk && $radiusOk;
        $this->log(
            $account,
            'activate',
            $success ? 'success' : ($routerOk !== $radiusOk ? 'partial' : 'failed'),
            $routerOk,
            $radiusOk,
            $this->failureReason('activate', $routerOk, $radiusOk),
            "Activated account {$account->username} (router={$routerOk}, radius={$radiusOk})"
        );

        return $success;
    }

    public function deprovisionAccount(ClientAccount $account, ?int $routerId = null): bool
    {
        $routerOk = $this->routerAdapter->deleteUser($account->username);
        $radiusOk = $this->radiusAdapter->deleteUser($account->username);

        $success = $routerOk && $radiusOk;
        $this->log(
            $account,
            'deprovision',
            $success ? 'success' : 'failed',
            $routerOk,
            $radiusOk,
            $this->failureReason('deprovision', $routerOk, $radiusOk),
            "Deprovisioned account {$account->username}"
        );

        return $success;
    }

    public function deprovisionUsername(string $username): bool
    {
        $routerOk = $this->routerAdapter->deleteUser($username);
        $radiusOk = $this->radiusAdapter->deleteUser($username);

        $success = $routerOk && $radiusOk;
        $this->log(
            null,
            'deprovision',
            $success ? 'success' : 'failed',
            $routerOk,
            $radiusOk,
            $this->failureReason('deprovision', $routerOk, $radiusOk),
            "Deprovisioned username {$username}"
        );

        return $success;
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

    /**
     * Record a structured provisioning outcome.
     */
    protected function log(?ClientAccount $account, string $operation, string $status, ?bool $routerOk, ?bool $radiusOk, ?string $failureReason, string $message): void
    {
        MikrotikSyncLog::create([
            'client_account_id' => $account?->id,
            'operation'         => $operation,
            'status'            => $status,
            'router_ok'         => $routerOk,
            'radius_ok'         => $radiusOk,
            'failure_reason'    => $failureReason,
            'log_message'       => $message,
        ]);
        Log::info('ProvisioningService: ' . $message);
    }

        protected function failureReason(string $operation, bool $routerOk, bool $radiusOk): ?string
    {
        $parts = [];
        if (!$routerOk) {
            $parts[] = 'router adapter failed';
        }
        if (!$radiusOk) {
            $parts[] = 'radius adapter failed';
        }

                return $parts ? $operation . ': ' . implode(', ', $parts) : null;
    }
}
