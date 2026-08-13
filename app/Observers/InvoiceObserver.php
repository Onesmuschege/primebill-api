<?php

namespace App\Observers;

use App\Events\InvoiceGenerated;
use App\Events\InvoiceOverdue;
use App\Models\Invoice;
use App\Services\Automation\Automation;

class InvoiceObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function created(Invoice $invoice): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        event(new InvoiceGenerated($invoice));
    }

    public function updated(Invoice $invoice): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        if ($invoice->getOriginal('status') !== 'overdue' && $invoice->status === 'overdue') {
            event(new InvoiceOverdue($invoice));
        }
    }
}
