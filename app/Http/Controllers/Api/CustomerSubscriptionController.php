<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerSubscription;
use App\Models\Client;
use App\Services\Customer\CustomerSubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerSubscriptionController extends Controller
{
    use ApiResponse;

    private function checkPermission(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }

    public function __construct(private CustomerSubscriptionService $subscriptionService) {}

    /**
     * GET /api/clients/{client}/subscriptions
     * List client subscriptions
     */
    public function index(Request $request, Client $client)
    {
        if (!$this->checkPermission('view subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        $status = $request->query('status');
        $type = $request->query('type');

        $query = CustomerSubscription::with(['product', 'plan'])
            ->where('client_id', $client->id)
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($type) {
            $query->where('type', $type);
        }

        $subscriptions = $query->paginate(15);

        return $this->success($subscriptions);
    }

    /**
     * GET /api/clients/{client}/subscriptions/{subscription}
     * Show client subscription
     */
    public function show(Client $client, CustomerSubscription $subscription)
    {
        if (!$this->checkPermission('view subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        if ($subscription->client_id !== $client->id) {
            return $this->error('Subscription not found for this client', null, 404);
        }

        $subscription->load(['product', 'plan', 'invoices']);

        return $this->success($subscription);
    }

    /**
     * POST /api/clients/{client}/subscriptions
     * Create client subscription
     */
    public function store(Request $request, Client $client)
    {
        if (!auth()->user()->can('create subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'plan_id' => 'required|exists:plans,id',
            'name' => 'nullable|string|max:255',
            'type' => 'in:new,upgrade,downgrade,renewal,addon',
            'discount' => 'nullable|numeric|min:0',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'contract_period_months' => 'nullable|integer|min:1',
            'auto_renew' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $subscription = $this->subscriptionService->create($client, $validated);

        return $this->success($subscription->load(['product', 'plan']), 'Subscription created successfully', 201);
    }

    /**
     * POST /api/clients/{client}/subscriptions/{subscription}/activate
     * Activate subscription
     */
    public function activate(Client $client, CustomerSubscription $subscription)
    {
        if (!auth()->user()->can('edit subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        if ($subscription->client_id !== $client->id) {
            return $this->error('Subscription not found for this client', null, 404);
        }

        $subscription = $this->subscriptionService->activate($subscription);

        return $this->success($subscription, 'Subscription activated successfully');
    }

    /**
     * POST /api/clients/{client}/subscriptions/{subscription}/suspend
     * Suspend subscription
     */
    public function suspend(Request $request, Client $client, CustomerSubscription $subscription)
    {
        if (!auth()->user()->can('edit subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        if ($subscription->client_id !== $client->id) {
            return $this->error('Subscription not found for this client', null, 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $subscription = $this->subscriptionService->suspend($subscription, $validated['reason'] ?? null);

        return $this->success($subscription, 'Subscription suspended successfully');
    }

    /**
     * POST /api/clients/{client}/subscriptions/{subscription}/resume
     * Resume subscription
     */
    public function resume(Client $client, CustomerSubscription $subscription)
    {
        if (!auth()->user()->can('edit subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        if ($subscription->client_id !== $client->id) {
            return $this->error('Subscription not found for this client', null, 404);
        }

        $subscription = $this->subscriptionService->resume($subscription);

        return $this->success($subscription, 'Subscription resumed successfully');
    }

    /**
     * POST /api/clients/{client}/subscriptions/{subscription}/cancel
     * Cancel subscription
     */
    public function cancel(Request $request, Client $client, CustomerSubscription $subscription)
    {
        if (!auth()->user()->can('edit subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        if ($subscription->client_id !== $client->id) {
            return $this->error('Subscription not found for this client', null, 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $subscription = $this->subscriptionService->cancel($subscription, $validated['reason'] ?? null);

        return $this->success($subscription, 'Subscription cancelled successfully');
    }

    /**
     * POST /api/clients/{client}/subscriptions/{subscription}/upgrade
     * Upgrade/downgrade subscription
     */
    public function upgrade(Request $request, Client $client, CustomerSubscription $subscription)
    {
        if (!auth()->user()->can('edit subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        if ($subscription->client_id !== $client->id) {
            return $this->error('Subscription not found for this client', null, 404);
        }

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $newPlan = \App\Models\Plan::findOrFail($validated['plan_id']);

        // Validate downgrade
        if ($newPlan->price < $subscription->price) {
            // Allow downgrade but log it
            Log::info('Subscription downgrade', [
                'subscription_id' => $subscription->id,
                'old_price' => $subscription->price,
                'new_price' => $newPlan->price,
            ]);
        }

        $subscription = $this->subscriptionService->upgrade($subscription, $newPlan, $validated['reason'] ?? null);

        return $this->success($subscription->load('plan'), 'Subscription upgraded successfully');
    }

    /**
     * POST /api/clients/{client}/subscriptions/{subscription}/renew
     * Renew subscription
     */
    public function renew(Client $client, CustomerSubscription $subscription)
    {
        if (!auth()->user()->can('edit subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        if ($subscription->client_id !== $client->id) {
            return $this->error('Subscription not found for this client', null, 404);
        }

        $subscription = $this->subscriptionService->renew($subscription);

        return $this->success($subscription, 'Subscription renewed successfully');
    }

    /**
     * GET /api/clients/{client}/subscriptions/active
     * Get active subscriptions for client
     */
    public function active(Client $client)
    {
        if (!auth()->user()->can('view subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        $subscriptions = CustomerSubscription::with(['product', 'plan'])
            ->where('client_id', $client->id)
            ->active()
            ->paginate(15);

        return $this->success($subscriptions);
    }

    /**
     * GET /api/clients/{client}/subscriptions/expiring-soon
     * Get subscriptions expiring in next 7 days
     */
    public function expiringSoon(Client $client)
    {
        if (!auth()->user()->can('view subscriptions')) {
            return $this->error('Unauthorized', null, 403);
        }

        $subscriptions = CustomerSubscription::with(['product', 'plan'])
            ->where('client_id', $client->id)
            ->expiringSoon()
            ->paginate(15);

        return $this->success($subscriptions);
    }
}
