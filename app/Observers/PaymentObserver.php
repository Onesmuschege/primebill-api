<?php

namespace App\Observers;

use App\Events\PaymentReceived;
use App\Events\PaymentFailed;
use App\Models\Payment;
use App\Services\Automation\Automation;

class PaymentObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function created(Payment $payment): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        if ($payment->status === 'paid') {
            event(new PaymentReceived($payment));
        }
    }

    public function updated(Payment $payment): void
    {
        if (! $this->automation->isEnabled()) {
            return;
        }
        $before = $payment->getOriginal('status');

        if ($payment->status === 'paid' && $before !== 'paid') {
            event(new PaymentReceived($payment));
        }
        if (in_array($payment->status, ['failed', 'declined'], true) && ! in_array($before, ['failed', 'declined'], true)) {
            event(new PaymentFailed($payment));
        }
    }
}
