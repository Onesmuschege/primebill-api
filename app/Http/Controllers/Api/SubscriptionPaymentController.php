<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantSubscription;
use App\Services\Mpesa\MpesaService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private MpesaService $mpesaService) {}

    /**
     * POST /api/subscription/payment/initiate
     * Initiate M-Pesa payment for subscription
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|exists:tenant_subscriptions,id',
            'phone_number' => 'required|string|regex:/^254[0-9]{9}$/',
        ]);

        $tenant = \App\Models\Tenant::current();
        $subscription = TenantSubscription::where('id', $request->subscription_id)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        if ($subscription->status === 'cancelled') {
            return $this->error('Cannot process payment for cancelled subscription', null, 422);
        }

        try {
            $response = $this->mpesaService->initiateSTKPush([
                'phone_number' => $request->phone_number,
                'amount' => $subscription->price,
                'account_reference' => "SUB-{$subscription->id}",
                'transaction_desc' => "Subscription payment - {$subscription->plan->name}",
            ]);

            // Store payment reference
            $subscription->update([
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'pending_payment_ref' => $response['checkout_request_id'],
                    'pending_payment_at' => now()->format('Y-m-d H:i:s'),
                ]),
            ]);

            return $this->success($response, 'Payment initiated. Please check your phone to complete.');
        } catch (\Exception $e) {
            Log::error('Subscription payment initiation failed', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to initiate payment. Please try again.', null, 500);
        }
    }

    /**
     * POST /api/subscription/payment/callback
     * Handle M-Pesa callback for subscription payment
     */
    public function callback(Request $request)
    {
        $data = $request->all();

        Log::info('Subscription payment callback received', $data);

        $checkoutId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;
        $resultCode = $data['Body']['stkCallback']['ResultCode'] ?? null;

        if (!$checkoutId) {
            return response()->json(['status' => 'error'], 400);
        }

        // Find subscription by pending payment reference
        $subscription = TenantSubscription::whereHas('tenant', function ($q) {
                $q->where('id', \App\Models\Tenant::current()?->id);
            })
            ->where('metadata->pending_payment_ref', $checkoutId)
            ->first();

        if (!$subscription) {
            Log::warning('Subscription not found for payment callback', ['checkout_id' => $checkoutId]);
            return response()->json(['status' => 'not_found'], 404);
        }

        if ($resultCode === 0) {
            // Payment successful
            $subscription->update([
                'status' => 'active',
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'last_payment_ref' => $checkoutId,
                    'last_payment_at' => now()->format('Y-m-d H:i:s'),
                    'payment_status' => 'completed',
                ]),
            ]);

            // Update tenant plan
            $subscription->tenant->update([
                'plan' => $subscription->plan->slug,
                'plan_started_at' => now(),
                'plan_expires_at' => $subscription->ends_at,
            ]);

            Log::info('Subscription payment completed', [
                'subscription_id' => $subscription->id,
                'tenant_id' => $subscription->tenant_id,
            ]);
        } else {
            // Payment failed
            $subscription->update([
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'payment_status' => 'failed',
                    'payment_failed_at' => now()->format('Y-m-d H:i:s'),
                ]),
            ]);

            Log::warning('Subscription payment failed', [
                'subscription_id' => $subscription->id,
                'result_code' => $resultCode,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * GET /api/subscription/payment/history
     * Get subscription payment history
     */
    public function history()
    {
        $tenant = \App\Models\Tenant::current();

        // This would fetch from a payments table if we had one
        // For now, return metadata
        $subscription = $tenant->subscription()->where('status', '!=', 'cancelled')->first();

        if (!$subscription) {
            return $this->success([]);
        }

        $payments = $subscription->metadata['payments'] ?? [];

        return $this->success($payments);
    }
}
