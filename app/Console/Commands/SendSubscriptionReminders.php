<?php

namespace App\Console\Commands;

use App\Models\TenantSubscription;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:send-reminders';

    protected $description = 'Send subscription expiry reminders to tenants';

    public function __construct(protected NotificationService $notificationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $sent = 0;

        // Remind at 7 days, 3 days, and 1 day before expiry
        $reminderWindows = [7, 3, 1];

        foreach ($reminderWindows as $daysBefore) {
            $remindDate = $now->copy()->addDays($daysBefore);

            $subscriptions = TenantSubscription::whereIn('status', ['active', 'trial'])
                ->where('ends_at', '<=', $remindDate)
                ->where('ends_at', '>', $now)
                ->with('tenant', 'plan')
                ->get();

            foreach ($subscriptions as $subscription) {
                try {
                    $this->notificationService->sendSubscriptionReminder(
                        $subscription->tenant,
                        $subscription,
                        $daysBefore
                    );

                    $sent++;
                } catch (\Exception $e) {
                    Log::error('Failed to send subscription reminder', [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Sent {$sent} subscription reminders.");

        return 0;
    }
}
