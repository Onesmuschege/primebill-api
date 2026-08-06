<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SuspendExpiredTenants extends Command
{
    protected $signature = 'subscriptions:suspend-expired';

    protected $description = 'Suspend tenants with expired subscriptions past grace period';

    public function handle(): int
    {
        $now = now();
        $suspended = 0;

        // Find tenants with subscriptions past grace period
        $expiredSubscriptions = TenantSubscription::whereIn('status', ['expired', 'past_due'])
            ->whereHas('tenant', function ($query) {
                $query->where('status', '!=', 'suspended');
            })
            ->with('tenant')
            ->get();

        foreach ($expiredSubscriptions as $subscription) {
            $tenant = $subscription->tenant;

            if (!$tenant || $tenant->status === 'suspended') {
                continue;
            }

            // Check if past grace period
            $graceEnds = $subscription->ends_at->addDays($subscription->grace_days);

            if ($now->gt($graceEnds)) {
                try {
                    $tenant->update(['status' => 'suspended']);
                    $subscription->update([
                        'status' => 'suspended',
                        'suspended_at' => $now,
                        'metadata' => array_merge($subscription->metadata ?? [], ['suspension_reason' => 'subscription_expired']),
                    ]);

                    Log::info('Tenant suspended due to expired subscription', [
                        'tenant_id' => $tenant->id,
                        'subscription_id' => $subscription->id,
                    ]);

                    $suspended++;
                } catch (\Exception $e) {
                    Log::error('Failed to suspend tenant', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Suspended {$suspended} tenants due to expired subscriptions.");

        return 0;
    }
}
