<?php

namespace App\Jobs;

use App\Models\ClientAccount;
use App\Services\Network\ProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ActivateNetworkAccessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $accountId
    ) {
        $this->onQueue(config('network.provisioning_queue', 'default'));
    }

    public function handle(ProvisioningService $provisioning): void
    {
        $account = ClientAccount::find($this->accountId);

        if ($account) {
            $provisioning->activateAccount($account);
        }
    }
}
