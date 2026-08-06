<?php

namespace App\Services\Notification;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\Log;

class NotificationService
{

    public function sendSubscriptionReminder(Tenant $tenant, TenantSubscription $subscription, int $daysBefore): void
    {
        $subject = "Subscription expires in {$daysBefore} days";
        $message = "Your {$subscription->plan->name} subscription expires on {$subscription->ends_at->format('Y-m-d')}";

        // Log the reminder (replace with actual mail/SMS implementation)
        Log::info('Subscription reminder', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'days_before' => $daysBefore,
            'subject' => $subject,
        ]);

        // TODO: Implement actual email/SMS sending
        // Mail::to($tenant->contact_email)->send(new SubscriptionReminderMail($subscription, $daysBefore));
    }

    public function sendTrialStartedNotification(Tenant $tenant, TenantSubscription $subscription): void
    {
        Log::info('Trial started notification', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
        ]);
    }

    public function sendPaymentReceivedNotification(Tenant $tenant, string $amount): void
    {
        Log::info('Payment received notification', [
            'tenant_id' => $tenant->id,
            'amount' => $amount,
        ]);
    }

    public function sendSubscriptionCancelledNotification(Tenant $tenant, TenantSubscription $subscription): void
    {
        Log::info('Subscription cancelled notification', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
        ]);
    }
}
