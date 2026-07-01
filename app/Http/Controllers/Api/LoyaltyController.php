<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoints;
use App\Models\Client;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    // GET /api/loyalty/points/{client_id}
    public function getPoints($clientId)
    {
        $client = Client::findOrFail($clientId);
        $points = LoyaltyPoints::where('client_id', $clientId)->first();

        if (!$points) {
            $points = LoyaltyPoints::create(['client_id' => $clientId]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'balance'      => $points->balance,
                'expires_at'   => $points->expires_at,
                'value_ksh'    => $points->balance * 0.10, // 1 point = KSH 0.10
            ],
        ]);
    }

    // POST /api/loyalty/redeem
    public function redeem(Request $request)
    {
        $request->validate([
            'points'  => 'required|integer|min:1',
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        $client = $request->user();
        $points = LoyaltyPoints::where('client_id', $client->id)->first();

        if (!$points || $points->balance < $request->points) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient loyalty points',
            ], 422);
        }

        $credit = $request->points * 0.10; // KSH 0.10 per point
        $points->redeemPoints($request->points, "Redeemed towards invoice {$request->invoice_id}");

        return response()->json([
            'success' => true,
            'message' => 'Points redeemed successfully',
            'data'    => ['credit' => $credit],
        ]);
    }

    // GET /api/loyalty/transactions
    public function transactions(Request $request)
    {
        $client = $request->user();
        $points = LoyaltyPoints::where('client_id', $client->id)->first();

        if (!$points) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $transactions = $points->transactions()
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $transactions,
        ]);
    }
}
