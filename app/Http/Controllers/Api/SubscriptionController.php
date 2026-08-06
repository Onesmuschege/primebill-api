<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Subscription\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    use ApiResponse;

    protected SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * GET /api/subscription/plans
     * List available subscription plans.
     */
    public function plans()
    {
        $plans = SubscriptionPlan::where('is_active', true)->orderBy('sort_order')->get();

        return $this->success($plans);
    }

    /**
     * GET /api/subscription/current
     * Get current tenant's subscription status.
     */
    public function current(Request $request)
    {
        $tenant = Tenant::current();
        $subscription = $tenant ? $tenant->subscription()->with('plan')->first() : null;

        if (!$subscription) {
            return $this->success([
                'has_subscription' => false,
                'message' => 'No active subscription',
            ]);
        }

        return $this->success($this->subscriptionService->getStatusSummary($subscription));
    }

    /**
     * POST /api/subscription/start-trial
     * Start a trial subscription.
     */
    public function startTrial(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'trial_days' => 'nullable|integer|min:1|max:90',
        ]);

        $tenant = Tenant::current();
        $plan = SubscriptionPlan::findOrFail($request->plan_id);

        $subscription = $this->subscriptionService->startTrial($tenant, $plan, $request->trial_days);

        return $this->success($subscription, 'Trial started successfully', 201);
    }

    /**
     * POST /api/subscription/convert
     * Convert trial to paid subscription.
     */
    public function convert(Request $request)
    {
        $request->validate([
            'billing_cycle' => 'required|in:monthly,annual',
        ]);

        $tenant = Tenant::current();
        $subscription = $tenant ? $tenant->subscription()->where('status', 'trial')->first() : null;

        if (!$subscription) {
            return $this->error('No trial subscription found', null, 422);
        }

        $subscription = $this->subscriptionService->convertTrialToPaid($subscription, $request->billing_cycle);

        return $this->success($subscription, 'Subscription converted successfully');
    }

    /**
     * POST /api/subscription/cancel
     * Cancel subscription.
     */
    public function cancel(Request $request)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant = Tenant::current();
        $subscription = $tenant ? $tenant->subscription()->first() : null;

        if (!$subscription) {
            return $this->error('No subscription found', null, 422);
        }

        $subscription = $this->subscriptionService->cancel($subscription, $request->reason);

        return $this->success($subscription, 'Subscription cancelled');
    }

    /**
     * GET /api/subscription/invoices
     * List subscription invoices.
     */
    public function invoices(Request $request)
    {
        $tenant = Tenant::current();
        $invoices = $tenant ? $tenant->subscriptionInvoices()->latest()->paginate(15) : collect();

        return $this->success($invoices);
    }

    /**
     * GET /api/subscription/usage
     * Get current usage vs limits.
     */
    public function usage(Request $request)
    {
        $tenant = Tenant::current();
        $plan = $tenant ? $tenant->plan : null;

        return $this->success([
            'limits' => [
                'max_clients' => $tenant->max_clients,
                'max_users' => $tenant->max_users,
                'max_routers' => $tenant->max_routers,
                'storage_quota_gb' => $tenant->storage_quota_gb,
                'api_calls_per_month' => $tenant->api_calls_per_month,
            ],
            'usage' => [
                'clients' => $tenant->clients()->count(),
                'users' => $tenant->users()->count(),
                'routers' => $tenant->routers()->count(),
                'storage_used_mb' => $tenant->storage_used_mb,
                'api_calls_used' => $tenant->api_calls_used,
            ],
            'percentages' => [
                'clients' => $tenant->max_clients > 0 ? round(($tenant->clients()->count() / $tenant->max_clients) * 100, 2) : 0,
                'users' => $tenant->max_users > 0 ? round(($tenant->users()->count() / $tenant->max_users) * 100, 2) : 0,
                'routers' => $tenant->max_routers > 0 ? round(($tenant->routers()->count() / $tenant->max_routers) * 100, 2) : 0,
                'storage' => $tenant->getStorageUsagePercent(),
                'api_calls' => $tenant->getApiUsagePercent(),
            ],
        ]);
    }
}
