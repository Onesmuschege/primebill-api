<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Services\Subscription\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformSubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(protected SubscriptionService $subscriptionService) {}

    /**
     * GET /api/platform/subscriptions
     * List all tenant subscriptions (platform admin only)
     */
    public function index(Request $request)
    {
        $search = $request->query('search');
        $status = $request->query('status');

        $query = TenantSubscription::with(['tenant', 'plan']);

        if ($search) {
            $query->whereHas('tenant', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        $subscriptions = $query->orderByDesc('created_at')->paginate(15);

        return $this->success($subscriptions);
    }

    /**
     * GET /api/platform/subscription-stats
     * Get subscription statistics (platform admin only)
     */
    public function stats()
    {
        $stats = [
            'total_subscriptions' => TenantSubscription::count(),
            'active_subscriptions' => TenantSubscription::where('status', 'active')->count(),
            'trial_subscriptions' => TenantSubscription::where('status', 'trial')->count(),
            'past_due_subscriptions' => TenantSubscription::where('status', 'past_due')->count(),
            'suspended_subscriptions' => TenantSubscription::where('status', 'suspended')->count(),
            'cancelled_subscriptions' => TenantSubscription::where('status', 'cancelled')->count(),
            'mrr' => TenantSubscription::where('status', 'active')
                ->where('billing_cycle', 'monthly')
                ->sum('price'),
            'arr' => TenantSubscription::where('status', 'active')
                ->where('billing_cycle', 'annual')
                ->sum('price'),
        ];

        return $this->success($stats);
    }

    /**
     * POST /api/platform/subscriptions/{subscription}/upgrade
     * Upgrade/downgrade a tenant's subscription
     */
    public function upgrade(Request $request, TenantSubscription $subscription)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'billing_cycle' => 'required|in:monthly,annual',
            'reason' => 'nullable|string|max:500',
        ]);

        $newPlan = SubscriptionPlan::findOrFail($request->plan_id);
        $tenant = $subscription->tenant;

        // Validate downgrade
        if ($newPlan->max_clients < $tenant->clients()->count()) {
            return $this->error("Cannot downgrade: tenant has {$tenant->clients()->count()} clients but new plan only allows {$newPlan->max_clients}", null, 422);
        }

        DB::transaction(function () use ($subscription, $newPlan, $request, $tenant) {
            // Calculate proration
            $oldPrice = $subscription->price;
            $newPrice = $request->billing_cycle === 'annual' && $newPlan->annual_price
                ? $newPlan->annual_price
                : $newPlan->price;

            $proration = 0;
            if ($oldPrice > 0 && $newPrice > 0) {
                $daysRemaining = now()->diffInDays($subscription->ends_at);
                $totalDays = $subscription->billing_cycle === 'annual' ? 365 : 30;
                $proration = ($newPrice - $oldPrice) * ($daysRemaining / $totalDays);
            }

            // Update subscription
            $subscription->update([
                'plan_id' => $newPlan->id,
                'name' => "{$newPlan->name} " . ucfirst($request->billing_cycle),
                'billing_cycle' => $request->billing_cycle,
                'price' => $newPrice,
                'annual_price' => $newPlan->annual_price,
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'upgrade_reason' => $request->reason,
                    'proration' => $proration,
                    'upgraded_at' => now()->format('Y-m-d H:i:s'),
                ]),
            ]);

            // Apply new plan limits
            $tenant->update([
                'plan' => $newPlan->slug,
                'max_clients' => $newPlan->max_clients,
                'max_users' => $newPlan->max_users,
                'max_routers' => $newPlan->max_routers,
                'storage_quota_gb' => $newPlan->storage_quota_gb,
                'api_calls_per_month' => $newPlan->api_calls_per_month,
                'feature_flags' => $newPlan->features ?? [],
            ]);
        });

        return $this->success($subscription->fresh(), 'Subscription upgraded successfully');
    }

    /**
     * POST /api/platform/subscriptions/{subscription}/suspend
     * Suspend a subscription
     */
    public function suspend(Request $request, TenantSubscription $subscription)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $subscription = $this->subscriptionService->suspend($subscription, $request->reason);

        return $this->success($subscription, 'Subscription suspended successfully');
    }

    /**
     * POST /api/platform/subscriptions/{subscription}/resume
     * Resume a suspended subscription
     */
    public function resume(Request $request, TenantSubscription $subscription)
    {
        $subscription = $this->subscriptionService->resume($subscription);

        return $this->success($subscription, 'Subscription resumed successfully');
    }

    /**
     * POST /api/platform/subscriptions/{subscription}/cancel
     * Cancel a subscription
     */
    public function cancel(Request $request, TenantSubscription $subscription)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
            'cancel_at' => 'nullable|date|after:now',
        ]);

        $cancelAt = $request->cancel_at ? \Carbon\Carbon::parse($request->cancel_at) : null;
        $subscription = $this->subscriptionService->cancel($subscription, $request->reason, $cancelAt);

        return $this->success($subscription, 'Subscription cancelled successfully');
    }

    /**
     * POST /api/platform/subscriptions/{subscription}/renew
     * Manually renew a subscription
     */
    public function renew(Request $request, TenantSubscription $subscription)
    {
        $subscription = $this->subscriptionService->renew($subscription);

        return $this->success($subscription, 'Subscription renewed successfully');
    }
}
