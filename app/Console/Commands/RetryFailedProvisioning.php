<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\Tenant;
use App\Services\Network\ServiceLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFailedProvisioning extends Command
{
    protected $signature = 'network:retry-failed-provisioning';

    protected $description = 'Retry provisioning for accounts whose billing entitlement and network state diverge';

    public function handle(ServiceLifecycleService $lifecycle): int
    {
        $stats = $lifecycle->reconcileAll();

        // Surface any accounts left in a non-terminal pending state that the
        // reconcile pass could not resolve, for observability.
        $stuck = ClientAccount::whereIn('service_state', [
            ClientAccount::STATE_PENDING,
            ClientAccount::STATE_PROVISIONING,
        ])->count();

        Log::info('network:retry-failed-provisioning', [
            'reconcile' => $stats,
            'stuck_accounts' => $stuck,
        ]);

        $this->info('Retry pass complete: ' . json_encode($stats) . " (stuck_accounts={$stuck})");

        return self::SUCCESS;
    }
}