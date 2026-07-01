<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    // GET /api/referrals/code
    public function getCode(Request $request)
    {
        $client = $request->user();
        
        if (!$client->referral_code) {
            $client->update(['referral_code' => strtoupper(Str::random(6))]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'code'            => $client->referral_code,
                'referral_count'  => $client->referral_count,
                'referral_bonus'  => $client->referral_bonus,
            ],
        ]);
    }

    // POST /api/referrals/join
    public function join(Request $request)
    {
        $request->validate(['referral_code' => 'required|exists:clients,referral_code']);

        $referrer = Client::where('referral_code', $request->referral_code)->first();
        
        if (!$referrer) {
            return response()->json(['success' => false, 'message' => 'Invalid referral code'], 404);
        }

        $client = $request->user();
        
        if ($client->referred_by) {
            return response()->json(['success' => false, 'message' => 'Already referred'], 422);
        }

        $client->update(['referred_by' => $referrer->id]);
        $referrer->increment('referral_count');
        $referrer->increment('referral_bonus', 500); // KSH 500 per referral

        return response()->json([
            'success' => true,
            'message' => 'Referral applied successfully',
        ]);
    }

    // GET /api/referrals/stats
    public function stats(Request $request)
    {
        $client = $request->user();

        return response()->json([
            'success' => true,
            'data'    => [
                'referral_code'   => $client->referral_code,
                'referrals_count' => $client->referral_count,
                'referral_bonus'  => $client->referral_bonus,
                'referred_by'     => $client->referred_by ? $client->referrer?->full_name : null,
            ],
        ]);
    }
}
