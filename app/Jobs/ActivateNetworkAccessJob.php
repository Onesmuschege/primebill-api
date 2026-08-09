<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Network\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ActivateNetworkAccessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $accountId,
        public ?int $tenantId = null
    ) {
        $this->onQueue(config('network.provisioning_queue', 'default'));
    }

    public function handle(ProvisioningService $provisioning): void
    {
        $this->establishTenantContext();

        try {
            $account = ClientAccount::find($this->accountId);

            if ($account) {
                $provisioning->activateAccount($account);
            }
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
