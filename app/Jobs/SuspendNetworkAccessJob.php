<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Network\ServiceLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SuspendNetworkAccessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $accountId,
        public ?int $tenantId = null,
        public string $suspensionType = ClientAccount::SUSPENSION_BILLING,
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
                // The lifecycle service owns the transition, network
                // consequences and audit trail — this job only decides THAT
                // a suspension is required.
                $lifecycle->suspend($account, $this->reason ?? 'Scheduled/billing suspension', $this->suspensionType);
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
