<?php

namespace App\Console\Commands;

use App\Services\Network\ServiceLifecycleService;
use Illuminate\Console\Command;

class ReconcileServiceEntitlements extends Command
{
    protected $signature = 'network:reconcile-entitlements';

    protected $description = 'Reconcile service entitlements — suspend overdue services, restore paid services';

    public function handle(ServiceLifecycleService $lifecycle): int
    {
        $stats = $lifecycle->reconcileAll();

        $this->info("Entitlement reconciliation: " . json_encode($stats));

        return self::SUCCESS;
    }
}
