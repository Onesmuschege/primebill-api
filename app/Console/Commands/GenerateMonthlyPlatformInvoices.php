<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformBillingService;
use Illuminate\Console\Command;

/**
 * The PrimeBill ISP Platform's own subscription-invoice run: build one platform invoice per
 * active tenant ISP for the current (or given) billing period. Scheduled
 * monthly; safe to re-run (idempotent per tenant + period).
 */
class GenerateMonthlyPlatformInvoices extends Command
{
    protected $signature = 'platform:invoices:generate {--period= : Billing period as YYYY-MM (defaults to current month)}';

    protected $description = 'Generate PrimeBill ISP Platform subscription invoices for all active tenants';

    public function handle(PlatformBillingService $billing): int
    {
        $period = $this->option('period');

        $this->info('Generating platform invoices'.($period ? " for period {$period}" : '').'...');

        $result = $billing->generateMonthlyInvoices($period);

        $this->info("Generated {$result['invoices']} platform invoice(s).");
        if ($result['ids']) {
            $this->table(['ID'], array_map(fn ($id) => [$id], $result['ids']));
        }

        return self::SUCCESS;
    }
}
