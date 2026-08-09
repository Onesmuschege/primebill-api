<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Network\ProvisioningService;
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

    public function handle(ProvisioningService $provisioning): void
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

            $provisioning->provisionAccount($account, $this->plainPassword);
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
