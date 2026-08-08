<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyPoint;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    // GET /api/loyalty/points/{clientId}
    public function getPoints($clientId)
    {
        $client = Client::findOrFail($clientId);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $client->loyalty_points_balance,
                'referral_code' => $client->referral_code,
            ],
        ]);
    }

    // GET /api/loyalty/transactions
    public function transactions(Request $request)
    {
        $query = LoyaltyPoint::with('client:id,first_name,last_name');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $history = $query->latest()->paginate($request->per_page ?? 30);

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    // GET /api/loyalty/{client}
    public function show(Client $client)
    {
        $history = LoyaltyPoint::where('client_id', $client->id)
            ->latest()
            ->paginate(30);

        return response()->json([
            'success' => true,
            'data' => [
                'balance' => $client->loyalty_points_balance,
                'referral_code' => $client->referral_code,
                'history' => $history,
            ],
        ]);
    }

    // POST /api/loyalty/{client}/adjust (admin manual adjustment)
    public function adjust(Request $request, Client $client)
    {
        $request->validate([
            'points' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        LoyaltyPoint::create([
            'client_id' => $client->id,
            'points'    => $request->points,
            'type'      => 'adjustment',
            'reason'    => $request->reason,
        ]);

        if ($request->points > 0) {
            $client->increment('loyalty_points_balance', $request->points);
        } else {
            $client->decrement('loyalty_points_balance', abs($request->points));
        }

        return response()->json([
            'success' => true,
            'message' => 'Points adjusted',
            'data'    => ['balance' => $client->fresh()->loyalty_points_balance],
        ]);
    }

    // POST /api/loyalty/redeem
    public function redeem(Request $request)
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'points'     => 'required|integer|min:100',
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        $client = Client::findOrFail($request->client_id);

        // 1 point = Ksh 0.10 → 100 points = Ksh 10 minimum redemption
        $kesValue = $request->points * 0.10;

        $ok = LoyaltyPoint::redeem(
            $client->id,
            $request->points,
            "Redeemed against Invoice #{$request->invoice_id}",
        );

        if (!$ok) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient loyalty points.',
            ], 422);
        }

        // Apply discount to the invoice (update amount due)
        $invoice = \App\Models\Invoice::find($request->invoice_id);
        $newTotal = max(0, $invoice->total - $kesValue);
        $invoice->update(['total' => $newTotal]);

        return response()->json([
            'success' => true,
            'message' => "Redeemed {$request->points} points (Ksh {$kesValue} discount applied).",
        ]);
    }

    // GET /api/loyalty/leaderboard
    public function leaderboard()
    {
        $leaders = Client::orderByDesc('loyalty_points_balance')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'loyalty_points_balance']);

        return response()->json(['success' => true, 'data' => $leaders]);
    }
}
