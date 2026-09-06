<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\MikrotikSyncLog;
use App\Models\Tenant;
use App\Services\Network\ProvisioningService;
use App\Services\Network\ServiceLifecycleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProvisionClientAccountJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    /**
     * Windows after which an exclusively-locked job may be enqueued again.
     * Prevents duplicate dispatch while an attempt for this account is queued.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public int $accountId,
        public string $plainPassword,
        public ?int $tenantId = null,
        public ?string $idempotencyKey = null
    ) {
        $this->onQueue(config('network.provisioning_queue', 'default'));
    }

    /**
     * One logical provision attempt per account while queued/in-flight.
     */
    public function uniqueId(): string
    {
        return "provision:{$this->accountId}";
    }

    /**
     * Exponential backoff between queue retries: 30s → 2m → 5m.
     */
    public function backoff(): array
    {
        return config('network.provisioning_backoff', [30, 120, 300]);
    }

    public function handle(ProvisioningService $provisioning, ServiceLifecycleService $lifecycle): void
    {
        // Establish tenant context from the job payload so the global scope
        // applies and this job can never operate on another tenant's data.
        $this->establishTenantContext();

        try {
            $account = ClientAccount::with('plan')->find($this->accountId);

            if (!$account) {
                Log::warning('ProvisionClientAccountJob: account not found', ['id' => $this->accountId]);

                return;
            }

            $key = $this->idempotencyKey ?? "provision:{$account->id}";

            // Idempotency guard — a previously SUCCESSFUL attempt for the same
            // logical key is a no-op. Failed attempts keep their key across
            // backoff retries (they never recorded success), so retrying is
            // still safe and attributable.
            $alreadySucceeded = MikrotikSyncLog::where('client_account_id', $account->id)
                ->where('operation', 'provision')
                ->where('idempotency_key', $key)
                ->where('status', 'success')
                ->exists();

            if ($alreadySucceeded) {
                Log::info('ProvisionClientAccountJob: idempotent skip (already provisioned)', [
                    'account_id'      => $account->id,
                    'idempotency_key' => $key,
                ]);

                return;
            }

            $ok = $provisioning->provisionAccount($account, $this->plainPassword, $key);

            if (!$ok) {
                // Throw so the queue retries with backoff (tries=3). The
                // failure audit row + provisioning.failed event are already
                // written by ProvisioningService before this point.
                throw new RuntimeException(
                    "Provisioning for account {$account->id} ({$account->username}) did not complete"
                );
            }

            // Provisioning succeeded on both enforcement layers. Complete the
            // lifecycle transition through the single authority so `status`
            // and `service_state` (and the audit trail) can never diverge.
            // force=false: if an operator suspended this service while the
            // job was queued, the administrative hold must survive.
            $lifecycle->activate($account, 'Network provisioning complete', false);

            if ($account->service_state !== ClientAccount::STATE_ACTIVE) {
                throw new RuntimeException(
                    "Activation for account {$account->id} did not complete (state={$account->service_state})"
                );
            }

            $account->update(['provisioned_at' => now()]);
        } finally {
            Tenant::setCurrent(null);
        }
    }

    /**
     * Terminal state after all retries are exhausted. The structured audit row
     * remains the source of truth; this only surfaces an operator log line.
     */
    public function failed(?Throwable $exception = null): void
    {
        Log::error('ProvisionClientAccountJob: exhausted retries', [
            'account_id'      => $this->accountId,
            'idempotency_key' => $this->idempotencyKey ?? "provision:{$this->accountId}",
            'error'           => $exception?->getMessage(),
        ]);
    }

    protected function establishTenantContext(): void
    {
        if ($this->tenantId) {
            $tenant = Tenant::find($this->tenantId);
            if ($tenant) {
                Tenant::setCurrent($tenant);
            }
        }
    }
}