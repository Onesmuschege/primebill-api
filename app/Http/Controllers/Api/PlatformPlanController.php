<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Catalog CRUD for PrimeBill's subscription plans — the plans that tenant
 * ISPs can be placed on. Replaces the old hardcoded Tenant::PLANS constant
 * with a managed DB-backed catalog.
 *
 * All endpoints are platform-admin gated. Deletion is blocked while any
 * subscription still references the plan (data-integrity guard).
 */
class PlatformPlanController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/platform/plans
     * List all plans in the catalog. Maps the DB `price` column to
     * `price_monthly` so frontend consumers (upgrade modal, plan page) keep
     * working without a breaking change.
     */
    public function index()
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'description' => $p->description,
                'billing_cycle' => $p->billing_cycle,
                'price_monthly' => (float) $p->price,
                'price' => (float) $p->price,
                'annual_price' => (float) $p->annual_price,
                'is_active' => $p->is_active,
                'is_trial_available' => $p->is_trial_available,
                'trial_days' => $p->trial_days,
                'grace_days' => $p->grace_days,
                'features' => $p->features ?? [],
                'max_clients' => $p->max_clients,
                'max_users' => $p->max_users,
                'max_routers' => $p->max_routers,
                'storage_quota_gb' => $p->storage_quota_gb,
                'api_calls_per_month' => $p->api_calls_per_month,
                'sort_order' => $p->sort_order,
            ]);

        return $this->success($plans);
    }

    /**
     * POST /api/platform/plans
     * Create a new plan.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $plan = SubscriptionPlan::create($validated);

        return $this->success($plan, 'Plan created', 201);
    }

    /**
     * GET /api/platform/plans/{plan}
     * Show a single plan.
     */
    public function show(SubscriptionPlan $plan)
    {
        return $this->success($plan);
    }

    /**
     * PUT /api/platform/plans/{plan}
     * Update a plan.
     */
    public function update(Request $request, SubscriptionPlan $plan)
    {
        $validated = $request->validate($this->rules($plan->id));

        $plan->update($validated);

        return $this->success($plan->fresh(), 'Plan updated');
    }

    /**
     * DELETE /api/platform/plans/{plan}
     * Delete a plan. Blocked while subscriptions still reference it.
     */
    public function destroy(SubscriptionPlan $plan)
    {
        $subscriptionCount = TenantSubscription::where('plan_id', $plan->id)->count();

        if ($subscriptionCount > 0) {
            return $this->error(
                "Cannot delete plan '{$plan->name}': {$subscriptionCount} subscription(s) still reference it.",
                null,
                409
            );
        }

        $plan->delete();

        return $this->success(null, 'Plan deleted');
    }

    /**
     * Validation rules for store/update.
     */
    protected function rules(?int $ignoreId = null): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:64',
                Rule::unique('subscription_plans', 'slug')->ignore($ignoreId),
            ],
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'billing_cycle' => 'required|in:monthly,annual',
            'price' => 'required|numeric|min:0',
            'annual_price' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'is_trial_available' => 'sometimes|boolean',
            'trial_days' => 'sometimes|integer|min:0|max:365',
            'grace_days' => 'sometimes|integer|min:0|max:90',
            'features' => 'sometimes|array',
            'features.*' => 'string|max:64',
            'max_clients' => 'sometimes|integer|min:0',
            'max_users' => 'sometimes|integer|min:0',
            'max_routers' => 'sometimes|integer|min:0',
            'storage_quota_gb' => 'sometimes|integer|min:0',
            'api_calls_per_month' => 'sometimes|integer|min:0',
            'sort_order' => 'sometimes|integer|min:0',
        ];
    }
}
