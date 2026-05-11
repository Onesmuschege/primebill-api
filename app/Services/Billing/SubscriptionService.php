<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Events\SubscriptionCreated;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionCancelled;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class SubscriptionService
{
    public function createSubscription(
        ClientAccount $account,
        Plan $plan,
        ?string $billingCycle = 'monthly'
    ): Subscription {
        $subscription = Subscription::create([
            'client_account_id' => $account->id,
            'plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'price' => $plan->price,
            'status' => 'active',
            'started_at' => now(),
            'renews_at' => $this->calculateRenewalDate($billingCycle),
            'auto_renew' => true,
        ]);

        SubscriptionCreated::dispatch($subscription);
        return $subscription;
    }

    public function renewSubscription(Subscription $subscription): bool
    {
        if (!$subscription->isRenewable()) {
            return false;
        }

        $subscription->update([
            'renews_at' => $this->calculateRenewalDate($subscription->billing_cycle),
            'status' => 'active',
            'renewed_at' => now(),
        ]);

        SubscriptionRenewed::dispatch($subscription);
        return true;
    }

    public function cancelSubscription(Subscription $subscription, ?string $reason = null): bool
    {
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        SubscriptionCancelled::dispatch($subscription);
        return true;
    }

    public function getUpcomingRenewals(int $daysAhead = 7): Collection
    {
        return Subscription::where('status', 'active')
            ->where('auto_renew', true)
            ->whereBetween('renews_at', [now(), now()->addDays($daysAhead)])
            ->get();
    }

    public function processAutoRenewals(): int
    {
        $count = 0;
        $todayRenewals = Subscription::where('status', 'active')
            ->where('auto_renew', true)
            ->where('renews_at', '<=', now())
            ->get();

        foreach ($todayRenewals as $subscription) {
            if ($this->renewSubscription($subscription)) {
                $count++;
            }
        }

        return $count;
    }

    private function calculateRenewalDate(string $billingCycle): Carbon
    {
        return match ($billingCycle) {
            'weekly' => now()->addWeek(),
            'monthly' => now()->addMonth(),
            'quarterly' => now()->addMonths(3),
            'annually' => now()->addYear(),
            default => now()->addMonth(),
        };
    }
}
