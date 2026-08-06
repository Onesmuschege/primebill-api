<?php

namespace App\Services\Notification;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\Client;
use App\Models\CustomerSubscription;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Customer-subscription variant of the trial-started notification.
     * The customer subscription service passes a Client + CustomerSubscription,
     * which is a different domain from the platform TenantSubscription.
     */
    public function sendCustomerSubscriptionTrialStarted(Client $client, CustomerSubscription $subscription): void
    {
        Log::info('Customer subscription trial started notification', [
            'client_id' => $client->id,
            'subscription_id' => $subscription->id,
            'subscription_name' => $subscription->name,
        ]);
    }

    /**
     * Customer-subscription variant of the cancelled notification.
     */
    public function sendCustomerSubscriptionCancelled(Client $client, CustomerSubscription $subscription): void
    {
        Log::info('Customer subscription cancelled notification', [
            'client_id' => $client->id,
            'subscription_id' => $subscription->id,
            'subscription_name' => $subscription->name,
        ]);
    }

    /**
     * Customer-subscription variant of the renewal reminder.
     */
    public function sendCustomerSubscriptionReminder(Client $client, CustomerSubscription $subscription, int $daysBefore): void
    {
        Log::info('Customer subscription renewal reminder', [
            'client_id' => $client->id,
            'subscription_id' => $subscription->id,
            'subscription_name' => $subscription->name,
            'days_before' => $daysBefore,
        ]);
    }

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
