<?php

namespace App\Observers;

use App\Events\PaymentReceived;
use App\Events\PaymentFailed;
use App\Models\Payment;
use App\Services\Automation\Automation;
use App\Services\Dashboard\DashboardService;

class PaymentObserver
{
    public function __construct(protected Automation $automation)
    {
    }

    public function created(Payment $payment): void
    {
        // Dashboard stats (income today/this month, etc.) are cached per-tenant;
        // any payment mutation must invalidate that cache regardless of whether
        // automations are enabled, or the dashboard shows stale/zero figures.
        DashboardService::invalidateStats();

        if (! $this->automation->isEnabled()) {
            return;
        }
        // NOTE: the payments.status enum is ['pending','completed','failed'] —
        // 'paid' is not a valid value and this check never matched, so
        // PaymentReceived never fired. Fixed to match the real enum.
        if ($payment->status === 'completed') {
            event(new PaymentReceived($payment));
        }
    }

    public function updated(Payment $payment): void
    {
        DashboardService::invalidateStats();

        if (! $this->automation->isEnabled()) {
            return;
        }
        $before = $payment->getOriginal('status');

        if ($payment->status === 'completed' && $before !== 'completed') {
            event(new PaymentReceived($payment));
        }
        if (in_array($payment->status, ['failed', 'declined'], true) && ! in_array($before, ['failed', 'declined'], true)) {
            event(new PaymentFailed($payment));
        }
    }
}
