<?php

namespace App\Providers;

use App\Listeners\AutomationListener;
use App\Models\AutomationRule;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\WorkOrder;
use App\Models\CustomerSubscription;
use App\Observers\CustomerSubscriptionObserver;
use App\Observers\DeviceObserver;
use App\Observers\InvoiceObserver;
use App\Observers\OltObserver;
use App\Observers\PaymentObserver;
use App\Observers\TicketObserver;
use App\Observers\WorkOrderObserver;
use App\Services\Automation\Automation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('automation.php'), 'automation');
    }

    public function boot(): void
    {
                        // One generic queued listener handles every automation event.
        foreach ((array) config('automation.events', []) as $event) {
            $fqcn = str_starts_with($event, 'App\\') ? $event : "App\\Events\\$event";
            if (class_exists($fqcn)) {
                Event::listen($fqcn, AutomationListener::class);
            }
        }

        // Model observers fire events from the operational moments.
        Payment::observe(PaymentObserver::class);
        Invoice::observe(InvoiceObserver::class);
        CustomerSubscription::observe(CustomerSubscriptionObserver::class);
        WorkOrder::observe(WorkOrderObserver::class);
        Ticket::observe(TicketObserver::class);
        Device::observe(DeviceObserver::class);
        Olt::observe(OltObserver::class);
    }
}
