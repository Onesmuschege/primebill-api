<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformBillingService;
use Illuminate\Console\Command;

/**
 * Daily overdue sweep for the PrimeBill ISP Platform's invoices to its tenants. Marks every
 * sent/draft invoice whose due date has passed as overdue. Safe to run
 * repeatedly.
 */
class ReconcilePlatformOverdueInvoices extends Command
{
    protected $signature = 'platform:invoices:reconcile-overdue';

    protected $description = 'Mark PrimeBill ISP Platform invoices past their due date as overdue';

    public function handle(PlatformBillingService $billing): int
    {
        $count = $billing->reconcileOverdue();

        $this->info("Reconciled platform invoices: {$count} marked overdue.");

        return self::SUCCESS;
    }
}
