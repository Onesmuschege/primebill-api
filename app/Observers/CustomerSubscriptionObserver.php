<?php

namespace App\Observers;

use App\Events\SubscriptionActivated;
use App\Events\SubscriptionSuspended;
use App\Events\SubscriptionTerminated;
use App\Models\CustomerSubscription;
use App\Services\Automation\Automation;

class CustomerSubscriptionObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function updated(CustomerSubscription $subscription): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        $before = $subscription->getOriginal('status');
        $after  = $subscription->status;

        event_match:
        if ($after === 'active' && $before !== 'active') {
            event(new SubscriptionActivated($subscription));
        } elseif ($after === 'suspended' && $before !== 'suspended') {
            event(new SubscriptionSuspended($subscription));
        } elseif (in_array($after, ['cancelled', 'completed', 'terminated'], true)
            && ! in_array($before, ['cancelled', 'completed', 'terminated'], true)) {
            event(new SubscriptionTerminated($subscription));
        }
    }
}
