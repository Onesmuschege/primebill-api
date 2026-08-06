<?php

namespace App\Services\Subscription;

use App\Models\Tenant;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\SubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionService
{
    public function startTrial(Tenant $tenant, SubscriptionPlan $plan, ?int $customTrialDays = null): TenantSubscription
    {
        if (!$plan->is_trial_available) {
            throw new InvalidArgumentException('Trial not available for this plan.');
        }

        $trialDays = $customTrialDays ?? $plan->trial_days;

        return TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'name' => "{$plan->name} Trial",
            'status' => 'trial',
            'billing_cycle' => $plan->billing_cycle,
            'price' => 0,
            'annual_price' => $plan->annual_price,
            'trial_ends_at' => now()->addDays($trialDays),
            'starts_at' => now(),
            'ends_at' => now()->addDays($trialDays),
            'grace_days' => $plan->grace_days,
        ]);
    }

    public function activate(Tenant $tenant, SubscriptionPlan $plan, string $billingCycle = 'monthly'): TenantSubscription
    {
        $price = $billingCycle === 'annual' && $plan->annual_price
            ? $plan->annual_price
            : $plan->price;

        $endsAt = $billingCycle === 'annual'
            ? now()->addYear()
            : now()->addMonth();

        $subscription = TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'name' => "{$plan->name} " . ucfirst($billingCycle),
            'status' => 'active',
            'billing_cycle' => $billingCycle,
            'price' => $price,
            'annual_price' => $plan->annual_price,
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'grace_days' => $plan->grace_days,
        ]);

        $this->applyPlanToTenant($tenant, $plan);

        return $subscription;
    }

    public function convertTrialToPaid(TenantSubscription $subscription, string $billingCycle = 'monthly'): TenantSubscription
    {
        if (!$subscription->isTrial()) {
            throw new InvalidArgumentException('Subscription is not in trial status.');
        }

        $plan = $subscription->plan;

        return DB::transaction(function () use ($subscription, $plan, $billingCycle) {
            $subscription->update([
                'status' => 'active',
                'billing_cycle' => $billingCycle,
                'price' => $billingCycle === 'annual' && $plan->annual_price ? $plan->annual_price : $plan->price,
                'trial_ends_at' => now(),
                'starts_at' => now(),
                'ends_at' => $billingCycle === 'annual' ? now()->addYear() : now()->addMonth(),
            ]);

            $this->applyPlanToTenant($subscription->tenant, $plan);

            return $subscription;
        });
    }

    public function suspend(TenantSubscription $subscription, ?string $reason = null): TenantSubscription
    {
        if (!$subscription->isActive() && !$subscription->isPastDue()) {
            throw new InvalidArgumentException('Only active or past-due subscriptions can be suspended.');
        }

        $subscription->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'metadata' => array_merge($subscription->metadata ?? [], ['suspension_reason' => $reason]),
        ]);

        return $subscription;
    }

    public function resume(TenantSubscription $subscription): TenantSubscription
    {
        if (!$subscription->isSuspended()) {
            throw new InvalidArgumentException('Only suspended subscriptions can be resumed.');
        }

        $subscription->update([
            'status' => 'active',
            'suspended_at' => null,
        ]);

        return $subscription;
    }

    public function cancel(TenantSubscription $subscription, ?string $reason = null, ?\DateTime $cancelAt = null): TenantSubscription
    {
        if ($subscription->isCancelled() || $subscription->isExpired()) {
            throw new InvalidArgumentException('Subscription is already cancelled or expired.');
        }

        $data = [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ];

        if ($cancelAt) {
            $data['cancels_at'] = $cancelAt;
            $data['status'] = 'active';
        }

        $subscription->update($data);

        return $subscription;
    }

    public function renew(TenantSubscription $subscription): TenantSubscription
    {
        if (!$subscription->isActive() && !$subscription->isPastDue()) {
            throw new InvalidArgumentException('Only active or past-due subscriptions can be renewed.');
        }

        $billingCycle = $subscription->billing_cycle;
        $endsAt = $billingCycle === 'annual' ? now()->addYear() : now()->addMonth();

        $subscription->update([
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'suspended_at' => null,
        ]);

        return $subscription;
    }

    public function generateInvoice(TenantSubscription $subscription): SubscriptionInvoice
    {
        $plan = $subscription->plan;
        $amount = $subscription->price;
        $taxRate = $subscription->tenant->tax_rate ?? 0;
        $taxAmount = $amount * ($taxRate / 100);
        $total = $amount + $taxAmount;

        return SubscriptionInvoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'SUB-' . strtoupper(uniqid()),
            'amount' => $amount,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'status' => 'pending',
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'line_items' => [
                ['description' => "{$plan->name} - " . ucfirst($subscription->billing_cycle), 'amount' => $amount],
                ['description' => 'Tax', 'amount' => $taxAmount],
            ],
        ]);
    }

    private function applyPlanToTenant(Tenant $tenant, SubscriptionPlan $plan): void
    {
        $tenant->update([
            'plan' => $plan->slug,
            'max_clients' => $plan->max_clients,
            'max_users' => $plan->max_users,
            'max_routers' => $plan->max_routers,
            'storage_quota_gb' => $plan->storage_quota_gb,
            'api_calls_per_month' => $plan->api_calls_per_month,
            'feature_flags' => $plan->features ?? [],
        ]);
    }

    public function getStatusSummary(TenantSubscription $subscription): array
    {
        $tenant = $subscription->tenant;
        $plan = $subscription->plan;

        return [
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'status' => $subscription->status,
                'billing_cycle' => $subscription->billing_cycle,
                'price' => $subscription->price,
                'trial_ends_at' => $subscription->trial_ends_at?->format('Y-m-d H:i:s'),
                'starts_at' => $subscription->starts_at?->format('Y-m-d H:i:s'),
                'ends_at' => $subscription->ends_at?->format('Y-m-d H:i:s'),
                'remaining_days' => $subscription->remaining_days,
            ],
            'plan' => [
                'slug' => $plan->slug,
                'name' => $plan->name,
                'features' => $plan->features,
                'limits' => [
                    'max_clients' => $plan->max_clients,
                    'max_users' => $plan->max_users,
                    'max_routers' => $plan->max_routers,
                    'storage_quota_gb' => $plan->storage_quota_gb,
                    'api_calls_per_month' => $plan->api_calls_per_month,
                ],
            ],
            'usage' => [
                'clients' => $tenant->clients()->count(),
                'users' => $tenant->users()->count(),
                'routers' => $tenant->routers()->count(),
                'storage_used_mb' => $tenant->storage_used_mb,
                'api_calls_used' => $tenant->api_calls_used,
            ],
        ];
    }
}
