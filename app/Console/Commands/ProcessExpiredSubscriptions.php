<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:process-expired';

    protected $description = 'Process expired subscriptions and handle grace periods';

    public function __construct(protected SubscriptionService $subscriptionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = now();
        $processed = 0;

        // 1. Expired trials → suspend
        $expiredTrials = TenantSubscription::where('status', 'trial')
            ->where('trial_ends_at', '<', $now)
            ->with('tenant')
            ->get();

        foreach ($expiredTrials as $subscription) {
            try {
                $subscription->update([
                    'status' => 'expired',
                    'metadata' => array_merge($subscription->metadata ?? [], ['expired_reason' => 'trial_expired']),
                ]);

                Log::info('Trial expired', ['subscription_id' => $subscription->id]);
                $processed++;
            } catch (\Exception $e) {
                Log::error('Failed to process expired trial', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }

        // 2. Expired active subscriptions → past_due or suspended
        $expiredActive = TenantSubscription::where('status', 'active')
            ->where('ends_at', '<', $now)
            ->with('tenant', 'plan')
            ->get();

        foreach ($expiredActive as $subscription) {
            try {
                $graceEnds = $subscription->ends_at->addDays($subscription->grace_days);

                if ($now->lte($graceEnds)) {
                    // Within grace period
                    $subscription->update([
                        'status' => 'past_due',
                        'metadata' => array_merge($subscription->metadata ?? [], ['grace_period_ends' => $graceEnds->format('Y-m-d H:i:s')]),
                    ]);
                } else {
                    // Past grace period → suspend
                    $subscription->update([
                        'status' => 'suspended',
                        'suspended_at' => $now,
                        'metadata' => array_merge($subscription->metadata ?? [], ['suspension_reason' => 'payment_overdue']),
                    ]);

                    // Disable tenant
                    $subscription->tenant->update(['status' => 'suspended']);
                }

                $processed++;
            } catch (\Exception $e) {
                Log::error('Failed to process expired subscription', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Processed {$processed} expired subscriptions.");

        return 0;
    }
}
