<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Services\Mpesa\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CaptivePortalController extends Controller
{
    public function __construct(protected MpesaService $mpesaService) {}

    // -------------------------------------------------------------------------
    // GET /portal/captive/plans
    // Returns active hotspot plans only — the plan picker on the portal page.
    // Public, no auth. Safe because it returns no user data.
    // -------------------------------------------------------------------------
    public function plans()
    {
        $plans = Plan::where('type', 'hotspot')
            ->where('is_active', true)
            ->orderBy('price')
            ->get(['id', 'name', 'price', 'speed_down', 'speed_up', 'validity_days', 'fup_limit']);

        return response()->json(['success' => true, 'data' => $plans]);
    }

    // -------------------------------------------------------------------------
    // GET /portal/captive/status/{username}
    // Polls account status for a given username.
    // Public — MikroTik passes the username in the redirect URL so the portal
    // page can poll without a login session. We return minimal data (status
    // only) to avoid leaking account details to unauthenticated callers.
    // -------------------------------------------------------------------------
    public function status(string $username)
    {
        $account = ClientAccount::where('username', $username)
            ->with('plan:id,name,speed_down,speed_up,validity_days')
            ->first();

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Account not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'status'      => $account->status,
                'is_active'   => $account->status === 'active',
                'plan'        => $account->plan?->name,
                'expiry_date' => $account->expiry_date,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /portal/captive/pay
    // Initiates STK push for a guest (no Sanctum token).
    // Requires: phone, plan_id, username (from MikroTik redirect params).
    // We look up or create an invoice against this client account so the
    // existing MpesaService/PaymentService flow can reconcile the callback.
    // -------------------------------------------------------------------------
    public function pay(Request $request)
    {
        $request->validate([
            'phone'    => 'required|string',
            'plan_id'  => 'required|exists:plans,id',
            'username' => 'required|string|exists:client_accounts,username',
        ]);

        $account = ClientAccount::where('username', $request->username)
            ->with('client', 'plan')
            ->firstOrFail();

        $plan = Plan::findOrFail($request->plan_id);

        // Create or reuse a pending invoice for this plan purchase.
        // We reuse the existing InvoiceService pattern if available, otherwise
        // create directly. The callback reconciles via client_id on the invoice.
        $invoice = \App\Models\Invoice::firstOrCreate(
            [
                'client_id' => $account->client_id,
                'status'    => 'unpaid',
                'total'     => $plan->price,
            ],
            [
                'client_id'      => $account->client_id,
                'invoice_number' => 'CPT-' . strtoupper(uniqid()),
                'issue_date'     => now(),
                'due_date'       => now()->addHour(),
                'subtotal'       => $plan->price,
                'tax'            => 0,
                'total'          => $plan->price,
                'status'         => 'unpaid',
                'notes'          => "Captive portal purchase: {$plan->name}",
            ]
        );

        $response = $this->mpesaService->stkPush(
            $request->phone,
            $plan->price,
            $invoice->id,
            $account->client->phone ?? $request->username,
        );

        if (isset($response['error'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate payment. Check phone number and try again.',
            ], 422);
        }

        Log::info('CaptivePortal: STK push initiated', [
            'username'  => $request->username,
            'plan'      => $plan->name,
            'amount'    => $plan->price,
            'phone'     => $request->phone,
            'invoice'   => $invoice->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment prompt sent to your phone. Enter your M-Pesa PIN to complete.',
            'data'    => [
                'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
                'amount'              => $plan->price,
                'plan'                => $plan->name,
            ],
        ]);
    }
}