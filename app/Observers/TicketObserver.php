<?php

namespace App\Observers;

use App\Events\TicketCreated;
use App\Events\SLABreached;
use App\Models\Ticket;
use App\Services\Automation\Automation;

class TicketObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function created(Ticket $ticket): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        event(new TicketCreated($ticket));
    }

    public function updated(Ticket $ticket): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        $breachedBefore = (bool) $ticket->getOriginal('sla_breached');
        if ((bool) $ticket->sla_breached && ! $breachedBefore) {
            event(new SLABreached($ticket));
        }
    }
}
