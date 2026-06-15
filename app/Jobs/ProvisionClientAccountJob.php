<?php

namespace App\Jobs;

use App\Models\ClientAccount;
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
        public string $plainPassword
    ) {
        $this->onQueue(config('network.provisioning_queue', 'default'));
    }

    public function handle(ProvisioningService $provisioning): void
    {
        $account = ClientAccount::with('plan')->find($this->accountId);

        if (!$account) {
            Log::warning('ProvisionClientAccountJob: account not found', ['id' => $this->accountId]);

            return;
        }

        $provisioning->provisionAccount($account, $this->plainPassword);
    }
}
