<?php

namespace App\Console\Commands;

use App\Models\TenantSubscription;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew';

    protected $description = 'Renew expired subscriptions that have been paid';

    public function __construct(protected SubscriptionService $subscriptionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $renewed = 0;

        // Find past_due subscriptions that might have been paid
        $pastDueSubscriptions = TenantSubscription::where('status', 'past_due')
            ->where('ends_at', '<', now())
            ->with('tenant', 'plan')
            ->get();

        foreach ($pastDueSubscriptions as $subscription) {
            try {
                // Check if tenant has paid the invoice (simplified - check recent payments)
                $hasRecentPayment = $subscription->tenant->payments()
                    ->where('status', 'completed')
                    ->where('created_at', '>=', $subscription->ends_at)
                    ->exists();

                if ($hasRecentPayment) {
                    $this->subscriptionService->renew($subscription);
                    $renewed++;

                    Log::info('Subscription renewed after payment', ['subscription_id' => $subscription->id]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to renew subscription', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Renewed {$renewed} subscriptions.");

        return 0;
    }
}
