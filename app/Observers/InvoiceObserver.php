<?php

namespace App\Observers;

use App\Events\InvoiceGenerated;
use App\Events\InvoiceOverdue;
use App\Models\Invoice;
use App\Services\Automation\Automation;
use App\Services\Dashboard\DashboardService;

class InvoiceObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function created(Invoice $invoice): void
    {
        // Overdue-invoice counts and totals feed the cached dashboard stats;
        // invalidate on every mutation, independent of automation toggles.
        DashboardService::invalidateStats();

        if (! $this->automation->isEnabled()) {
            return;
        }
        event(new InvoiceGenerated($invoice));
    }

    public function updated(Invoice $invoice): void
    {
        DashboardService::invalidateStats();

        if (! $this->automation->isEnabled()) {
            return;
        }
        if ($invoice->getOriginal('status') !== 'overdue' && $invoice->status === 'overdue') {
            event(new InvoiceOverdue($invoice));
        }
    }
}