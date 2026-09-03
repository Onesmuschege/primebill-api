<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Network\ServiceLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ActivateNetworkAccessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $accountId,
        public ?int $tenantId = null,
        public bool $force = false,
        public ?string $reason = null
    ) {
        $this->onQueue(config('network.provisioning_queue', 'default'));
    }

    public function handle(ServiceLifecycleService $lifecycle): void
    {
        $this->establishTenantContext();

        try {
            $account = ClientAccount::find($this->accountId);

            if ($account) {
                // `force` must only be true for explicit administrative
                // restores — billing-driven reactivation leaves it false so
                // administrative holds survive (SL2).
                $lifecycle->activate($account, $this->reason ?? 'Scheduled/billing reactivation', $this->force);
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
