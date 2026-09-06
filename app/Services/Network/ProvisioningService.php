<?php

namespace App\Services\Network;

use App\Models\ClientAccount;
use App\Models\MikrotikSyncLog;
use App\Events\ProvisioningFailed;
use App\Events\ProvisioningSucceeded;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Support\Facades\Log;

class ProvisioningService
{
    public function __construct(
        protected RouterAdapterInterface $routerAdapter,
        protected RadiusAdapterInterface $radiusAdapter,
        ?EffectiveRateResolver $rateResolver = null
    ) {
        // Allow legacy two-arg construction (tests / ad-hoc callers) without
        // losing the authoritative rate resolution.
        $this->rateResolver = $rateResolver ?? app(EffectiveRateResolver::class);
    }

    /**
     * Provision a service on both enforcement layers (router + RADIUS).
     *
     * Every attempt is attributed to an idempotency key. Re-running with the
     * same key does NOT double-apply: the caller (job/pipeline/UI) checks the
     * key before re-dispatching, and provisioning.succeeded/failed events give
     * subscribers a single truthful signal per logical attempt.
     */
    public function provisionAccount(ClientAccount $account, string $plainPassword, ?string $idempotencyKey = null): bool
    {
        $account->loadMissing('plan');

        $key = $idempotencyKey ?? $this->defaultKey('provision', $account);

        if (!$account->plan) {
            $this->log($account, 'provision', 'failed', null, null, 'No plan assigned', "Skipped provisioning for {$account->username}: no plan assigned", $key);
            ProvisioningFailed::dispatch($account, [
                'status'         => 'failed',
                'operation'      => 'provision',
                'reason'         => 'No plan assigned',
                'idempotency_key'=> $key,
            ]);

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

        $reason = $this->failureReason('provision', $routerOk, $radiusOk);

        $this->log(
            $account,
            'provision',
            $status,
            $routerOk,
            $radiusOk,
            $reason,
            sprintf(
                'Provisioned account %s (router=%s, radius=%s)',
                $account->username,
                $routerOk ? 'ok' : 'fail',
                $radiusOk ? 'ok' : 'fail'
            ),
            $key
        );

        if ($success) {
            ProvisioningSucceeded::dispatch($account, [
                'status'         => 'success',
                'operation'      => 'provision',
                'idempotency_key'=> $key,
            ]);
        } else {
            // Partial counts as failed for event subscribers — retry logic acts
            // on the same signal the audit trail exposes.
            Log::warning('ProvisioningService: partial failure', [
                'account_id'      => $account->id,
                'router_ok'       => $routerOk,
                'radius_ok'       => $radiusOk,
                'idempotency_key' => $key,
            ]);
            ProvisioningFailed::dispatch($account, [
                'status'         => $status,
                'operation'      => 'provision',
                'reason'         => $reason,
                'router_ok'      => $routerOk,
                'radius_ok'      => $radiusOk,
                'idempotency_key'=> $key,
            ]);
        }

        return $success;
    }

    public function suspendAccount(ClientAccount $account, ?string $idempotencyKey = null): bool
    {
        $key = $idempotencyKey ?? $this->defaultKey('suspend', $account);

        $routerOk = $this->routerAdapter->suspendUser($account->username);
        $radiusOk = $this->radiusAdapter->suspendUser($account->username);

        $success = $routerOk && $radiusOk;
        $status = $success ? 'success' : ($routerOk !== $radiusOk ? 'partial' : 'failed');
        $reason = $this->failureReason('suspend', $routerOk, $radiusOk);

        $this->log(
            $account,
            'suspend',
            $status,
            $routerOk,
            $radiusOk,
            $reason,
            "Suspended account {$account->username} (router={$routerOk}, radius={$radiusOk})",
            $key
        );

        $this->dispatchOutcome($success, $status, $account, 'suspend', $reason, $key, $routerOk, $radiusOk);

        return $success;
    }

    public function activateAccount(ClientAccount $account, ?string $idempotencyKey = null): bool
    {
        $key = $idempotencyKey ?? $this->defaultKey('activate', $account);

        $routerOk = $this->routerAdapter->unsuspendUser($account->username);
        $radiusOk = $this->radiusAdapter->unsuspendUser($account->username);

        $success = $routerOk && $radiusOk;
        $status = $success ? 'success' : ($routerOk !== $radiusOk ? 'partial' : 'failed');
        $reason = $this->failureReason('activate', $routerOk, $radiusOk);

        $this->log(
            $account,
            'activate',
            $status,
            $routerOk,
            $radiusOk,
            $reason,
            "Activated account {$account->username} (router={$routerOk}, radius={$radiusOk})",
            $key
        );

        $this->dispatchOutcome($success, $status, $account, 'activate', $reason, $key, $routerOk, $radiusOk);

        return $success;
    }

    public function deprovisionAccount(ClientAccount $account, ?int $routerId = null, ?string $idempotencyKey = null): bool
    {
        $key = $idempotencyKey ?? $this->defaultKey('deprovision', $account);

        $routerOk = $this->routerAdapter->deleteUser($account->username);
        $radiusOk = $this->radiusAdapter->deleteUser($account->username);

        $success = $routerOk && $radiusOk;
        $reason = $this->failureReason('deprovision', $routerOk, $radiusOk);

        $this->log(
            $account,
            'deprovision',
            $success ? 'success' : 'failed',
            $routerOk,
            $radiusOk,
            $reason,
            "Deprovisioned account {$account->username}",
            $key
        );

        $this->dispatchOutcome($success, $success ? 'success' : 'failed', $account, 'deprovision', $reason, $key, $routerOk, $radiusOk);

        return $success;
    }

    public function deprovisionUsername(string $username, ?string $idempotencyKey = null): bool
    {
        $key = $idempotencyKey ?? 'deprovision:user:'.sha1($username);

        $routerOk = $this->routerAdapter->deleteUser($username);
        $radiusOk = $this->radiusAdapter->deleteUser($username);

        $success = $routerOk && $radiusOk;
        $reason = $this->failureReason('deprovision', $routerOk, $radiusOk);

        $this->log(
            null,
            'deprovision',
            $success ? 'success' : 'failed',
            $routerOk,
            $radiusOk,
            $reason,
            "Deprovisioned username {$username}",
            $key
        );

        $this->dispatchOutcome($success, $success ? 'success' : 'failed', null, 'deprovision', $reason, $key, $routerOk, $radiusOk);

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
        // Section 23 — re-provisioning must honour an active FUP throttle.
        // Resolving through the single authority prevents a generic
        // re-provision from overwriting a FUP override with the base rate.
        return $this->rateResolver->effectiveRate($account);
    }

    /**
     * Record a structured provisioning outcome, attributed to an idempotency
     * key so duplicates of the same logical attempt are detectable.
     */
    protected function log(?ClientAccount $account, string $operation, string $status, ?bool $routerOk, ?bool $radiusOk, ?string $failureReason, string $message, ?string $idempotencyKey = null): void
    {
        MikrotikSyncLog::create([
            'client_account_id' => $account?->id,
            'operation'         => $operation,
            'status'            => $status,
            'router_ok'         => $routerOk,
            'radius_ok'         => $radiusOk,
            'failure_reason'    => $failureReason,
            'idempotency_key'   => $idempotencyKey,
            'log_message'       => $message,
        ]);
        Log::info('ProvisioningService: ' . $message);
    }

    /**
     * Stable default idempotency key for an account operation.
     */
    protected function defaultKey(string $operation, ClientAccount $account): string
    {
        return "{$operation}:{$account->id}";
    }

    /**
     * Dispatch provisioning.succeeded / provisioning.failed for an outcome.
     * `partial` is a failure for subscribers — attribute, retry, alert.
     */
    protected function dispatchOutcome(
        bool $success,
        string $status,
        ?ClientAccount $account,
        string $operation,
        ?string $reason,
        string $key,
        bool $routerOk,
        bool $radiusOk
    ): void {
        $context = [
            'status'         => $status,
            'operation'      => $operation,
            'idempotency_key'=> $key,
            'router_ok'      => $routerOk,
            'radius_ok'      => $radiusOk,
        ];

        if ($success) {
            ProvisioningSucceeded::dispatch($account, $context);

            return;
        }

        $context['reason'] = $reason;

        ProvisioningFailed::dispatch($account, $context);
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
