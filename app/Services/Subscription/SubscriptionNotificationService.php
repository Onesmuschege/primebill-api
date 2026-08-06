<?php

namespace App\Services\Subscription;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Log;

class SubscriptionNotificationService
{
    public function __construct(private NotificationService $notificationService) {}

    public function trialExpiring(TenantSubscription $subscription, int $daysLeft): void
    {
        $this->notificationService->sendSubscriptionReminder(
            $subscription->tenant,
            $subscription,
            $daysLeft
        );

        Log::info('Trial expiry notification sent', [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'days_left' => $daysLeft,
        ]);
    }

    public function subscriptionExpiring(TenantSubscription $subscription, int $daysLeft): void
    {
        $this->notificationService->sendSubscriptionReminder(
            $subscription->tenant,
            $subscription,
            $daysLeft
        );

        Log::info('Subscription expiry notification sent', [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'days_left' => $daysLeft,
        ]);
    }

    public function paymentFailed(TenantSubscription $subscription, string $reason): void
    {
        Log::warning('Subscription payment failed', [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'reason' => $reason,
        ]);

        // TODO: Send email/SMS notification to tenant
    }

    public function subscriptionCancelled(TenantSubscription $subscription): void
    {
        $this->notificationService->sendSubscriptionCancelledNotification(
            $subscription->tenant,
            $subscription
        );

        Log::info('Subscription cancellation notification sent', [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
        ]);
    }

    public function subscriptionRenewed(TenantSubscription $subscription): void
    {
        Log::info('Subscription renewed', [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'new_ends_at' => $subscription->ends_at,
        ]);

        // TODO: Send renewal confirmation
    }

    public function subscriptionUpgraded(TenantSubscription $subscription, string $oldPlan, string $newPlan): void
    {
        Log::info('Subscription upgraded', [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
            'old_plan' => $oldPlan,
            'new_plan' => $newPlan,
        ]);

        // TODO: Send upgrade confirmation
    }
}
