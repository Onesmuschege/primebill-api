<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Network\ProvisioningService;
use App\Services\Network\ServiceLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProvisionClientAccountJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $accountId,
        public string $plainPassword,
        public ?int $tenantId = null
    ) {
        $this->onQueue(config('network.provisioning_queue', 'default'));
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

            $ok = $provisioning->provisionAccount($account, $this->plainPassword);

            if (!$ok) {
                Log::warning('ProvisionClientAccountJob: provisioning did not complete', [
                    'account_id' => $account->id,
                    'username'   => $account->username,
                ]);

                // The service stays PENDING — mirroring the real network
                // state. RetryFailedProvisioning reconciles it later.
                return;
            }

            // Provisioning succeeded on both enforcement layers. Complete the
            // lifecycle transition through the single authority so `status`
            // and `service_state` (and the audit trail) can never diverge.
            // force=false: if an operator suspended this service while the
            // job was queued, the administrative hold must survive.
            $lifecycle->activate($account, 'Network provisioning complete', false);

            if ($account->service_state !== ClientAccount::STATE_ACTIVE) {
                Log::warning('ProvisionClientAccountJob: activation did not complete', [
                    'account_id'    => $account->id,
                    'service_state' => $account->service_state,
                ]);

                return;
            }

            $account->update(['provisioned_at' => now()]);
        } finally {
            Tenant::setCurrent(null);
        }
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
