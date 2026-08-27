<?php

namespace App\Observers;

use App\Events\ClientCreated;
use App\Events\ClientUpdated;
use App\Models\Client;
use App\Services\Automation\Automation;
use App\Services\Dashboard\DashboardService;

/**
 * Fires the ClientCreated / ClientUpdated automation events from the
 * operational moments of a Client model's lifecycle.
 *
 * This completes the event matrix declared in config/automation.php — every
 * other automated entity (Invoice, Payment, Subscription, WorkOrder, Ticket,
 * Device, OLT) already observes and dispatches its events; Client was the
 * one model left unwired, leaving ClientCreated/ClientUpdated permanently
 * silent. Dispatched only when the automation engine is enabled so the test
 * suite (AUTOMATION_ENABLED=false) is unaffected.
 */
class ClientObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function created(Client $client): void
    {
        // Total Clients / Account Status dashboard cards depend on Client
        // counts — invalidate the cache on every mutation, automations aside.
        DashboardService::invalidateStats();

        if (! $this->automation->isEnabled()) {
            return;
        }

        event(new ClientCreated($client));
    }

    public function updated(Client $client): void
    {
        DashboardService::invalidateStats();

        if (! $this->automation->isEnabled()) {
            return;
        }

        event(new ClientUpdated($client));
    }
}