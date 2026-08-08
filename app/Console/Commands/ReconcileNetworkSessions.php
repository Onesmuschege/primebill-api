<?php

namespace App\Console\Commands;

use App\Services\Network\SessionReconciliationService;
use Illuminate\Console\Command;

class ReconcileNetworkSessions extends Command
{
    protected $signature = 'network:reconcile-sessions {--stale-minutes=5 : Minutes without accounting to consider stale}';

    protected $description = 'Reconcile stale RADIUS sessions that have no Accounting-Stop';

    public function handle(SessionReconciliationService $reconciler): int
    {
        $staleMinutes = (int) $this->option('stale-minutes');
        $count = $reconciler->reconcileStaleSessions($staleMinutes);

        $this->info("Reconciled {$count} stale sessions.");

        return self::SUCCESS;
    }
}
