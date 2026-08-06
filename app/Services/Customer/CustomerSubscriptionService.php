<?php

namespace App\Services\Customer;

use App\Models\CustomerSubscription;
use App\Models\Client;
use App\Models\Plan;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerSubscriptionService
{
    public function __construct(private NotificationService $notificationService) {}

    public function create(Client $client, array $data): CustomerSubscription
    {
        $plan = Plan::findOrFail($data['plan_id']);

        return DB::transaction(function () use ($client, $data, $plan) {
            $subscription = CustomerSubscription::create([
                'tenant_id' => $client->tenant_id,
                'client_id' => $client->id,
                'product_id' => $data['product_id'],
                'plan_id' => $data['plan_id'],
                'name' => $data['name'] ?? $plan->name,
                'status' => 'pending',
                'type' => $data['type'] ?? 'new',
                'price' => $plan->price,
                'discount' => $data['discount'] ?? 0,
                'tax' => $this->calculateTax($plan->price, $data['discount'] ?? 0),
                'total' => $this->calculateTotal($plan->price, $data['discount'] ?? 0),
                'starts_at' => $data['starts_at'] ?? now(),
                'ends_at' => $data['ends_at'] ?? now()->addMonth(),
                'contract_period_months' => $data['contract_period_months'] ?? null,
                'auto_renew' => $data['auto_renew'] ?? false,
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]);

            // Update client status
            $client->update(['status' => 'active']);

            return $subscription;
        });
    }

    public function activate(CustomerSubscription $subscription): CustomerSubscription
    {
        $subscription->update([
            'status' => 'active',
            'activated_at' => now(),
        ]);

        $this->notificationService->sendCustomerSubscriptionTrialStarted(
            $subscription->client,
            $subscription
        );

        Log::info('Customer subscription activated', [
            'subscription_id' => $subscription->id,
            'client_id' => $subscription->client_id,
        ]);

        return $subscription;
    }

    public function suspend(CustomerSubscription $subscription, ?string $reason = null): CustomerSubscription
    {
        $subscription->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'metadata' => array_merge($subscription->metadata ?? [], [
                'suspension_reason' => $reason,
            ]),
        ]);

        Log::info('Customer subscription suspended', [
            'subscription_id' => $subscription->id,
            'reason' => $reason,
        ]);

        return $subscription;
    }

    public function resume(CustomerSubscription $subscription): CustomerSubscription
    {
        $subscription->update([
            'status' => 'active',
            'suspended_at' => null,
            'metadata' => array_merge($subscription->metadata ?? [], [
                'suspension_reason' => null,
            ]),
        ]);

        Log::info('Customer subscription resumed', [
            'subscription_id' => $subscription->id,
        ]);

        return $subscription;
    }

    public function cancel(CustomerSubscription $subscription, ?string $reason = null): CustomerSubscription
    {
        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'metadata' => array_merge($subscription->metadata ?? [], [
                'cancellation_reason' => $reason,
            ]),
        ]);

        $this->notificationService->sendCustomerSubscriptionCancelled(
            $subscription->client,
            $subscription
        );

        Log::info('Customer subscription cancelled', [
            'subscription_id' => $subscription->id,
            'reason' => $reason,
        ]);

        return $subscription;
    }

    public function upgrade(CustomerSubscription $subscription, Plan $newPlan, ?string $reason = null): CustomerSubscription
    {
        DB::transaction(function () use ($subscription, $newPlan, $reason) {
            $oldPlan = $subscription->plan;

            // Calculate proration
            $proration = 0;
            if ($subscription->price > 0 && $newPlan->price > 0) {
                $daysRemaining = now()->diffInDays($subscription->ends_at);
                $totalDays = $daysRemaining > 0 ? $daysRemaining : 30;
                $proration = ($newPlan->price - $subscription->price) * ($daysRemaining / $totalDays);
            }

            $subscription->update([
                'plan_id' => $newPlan->id,
                'name' => $newPlan->name,
                'price' => $newPlan->price,
                'discount' => 0,
                'tax' => $this->calculateTax($newPlan->price, 0),
                'total' => $this->calculateTotal($newPlan->price, 0),
                'type' => 'upgrade',
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'old_plan_id' => $oldPlan->id,
                    'old_plan_name' => $oldPlan->name,
                    'upgrade_reason' => $reason,
                    'proration' => $proration,
                    'upgraded_at' => now()->format('Y-m-d H:i:s'),
                ]),
            ]);

            Log::info('Customer subscription upgraded', [
                'subscription_id' => $subscription->id,
                'old_plan' => $oldPlan->name,
                'new_plan' => $newPlan->name,
                'proration' => $proration,
            ]);
        });

        return $subscription;
    }

    public function renew(CustomerSubscription $subscription): CustomerSubscription
    {
        $plan = $subscription->plan;

        $subscription->update([
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'type' => 'renewal',
            'metadata' => array_merge($subscription->metadata ?? [], [
                'renewed_at' => now()->format('Y-m-d H:i:s'),
            ]),
        ]);

        $this->notificationService->sendCustomerSubscriptionReminder(
            $subscription->client,
            $subscription,
            0
        );

        Log::info('Customer subscription renewed', [
            'subscription_id' => $subscription->id,
            'new_ends_at' => $subscription->ends_at,
        ]);

        return $subscription;
    }

    public function complete(CustomerSubscription $subscription): CustomerSubscription
    {
        $subscription->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        Log::info('Customer subscription completed', [
            'subscription_id' => $subscription->id,
        ]);

        return $subscription;
    }

    private function calculateTax(float $price, float $discount): float
    {
        $taxRate = 0.16; // 16% VAT - should come from settings
        return ($price - $discount) * $taxRate;
    }

    private function calculateTotal(float $price, float $discount): float
    {
        return $price - $discount + $this->calculateTax($price, $discount);
    }
}
