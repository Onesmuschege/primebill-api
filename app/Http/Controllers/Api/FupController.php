<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\FupLog;
use Illuminate\Http\Request;

class FupController extends Controller
{
    /**
     * GET /api/fup
     * List all client accounts that have FUP enabled.
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $accounts = ClientAccount::with([
                'client:id,first_name,last_name',
                'plan:id,name,fup_limit,speed_down,speed_up,fup_speed_up,fup_speed_down',
            ])
            ->whereHas('plan', function ($q) {
                $q->whereNotNull('fup_limit');
            })
            ->orderBy('username')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * GET /api/fup/status/{account_id}
     */
    public function status($accountId)
    {
        $account = ClientAccount::with([
                'plan',
                'radiusSessions',
            ])
            ->findOrFail($accountId);

        if (!$account->plan || !$account->plan->fup_limit) {
            return response()->json([
                'success' => true,
                'data' => [
                    'enabled' => false,
                    'message' => 'No FUP configured for this plan',
                ],
            ]);
        }

        $fupLog = FupLog::where('client_account_id', $accountId)->first();

        $bytesUsed = $fupLog?->bytes_used ?? 0;
        $limitBytes = $account->plan->fup_limit * 1024 * 1024;

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => true,
                'limit_gb' => $account->plan->fup_limit,
                'bytes_used' => $bytesUsed,
                'bytes_remaining' => max(0, $limitBytes - $bytesUsed),
                'percentage' => $limitBytes > 0
                    ? round(($bytesUsed / $limitBytes) * 100, 2)
                    : 0,
                'triggered' => (bool) $fupLog?->triggered_at,
                'triggered_at' => $fupLog?->triggered_at,
                'reset_at' => $fupLog?->reset_at,
                'throttled_up' => $account->plan->fup_speed_up,
                'throttled_down' => $account->plan->fup_speed_down,
            ],
        ]);
    }

    /**
     * POST /api/fup/reset/{account_id}
     */
    public function reset(Request $request, $accountId)
    {
        $account = ClientAccount::findOrFail($accountId);

        $fupLog = FupLog::firstOrCreate(
            [
                'client_account_id' => $account->id,
            ],
            [
                'bytes_used' => 0,
                'reset_at' => now(),
            ]
        );

        $fupLog->update([
            'bytes_used' => 0,
            'triggered_at' => null,
            'reset_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'FUP reset successfully.',
            'data' => $fupLog,
        ]);
    }

    /**
     * GET /api/fup/stats
     */
    public function stats()
    {
        $affectedAccounts = ClientAccount::whereHas('plan', function ($q) {
            $q->whereNotNull('fup_limit');
        })->count();

        $throttledEvents = FupLog::whereNotNull('triggered_at')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'affected_accounts' => $affectedAccounts,
                'throttled_events_this_month' => $throttledEvents,
            ],
        ]);
    }
}