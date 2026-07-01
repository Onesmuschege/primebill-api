<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FupLog;
use App\Models\ClientAccount;
use Illuminate\Http\Request;

class FupController extends Controller
{
    /**
     * GET /api/fup/logs
     * Get FUP logs for an account or all accounts
     */
    public function index(Request $request)
    {
        $request->validate([
            'account_id' => 'nullable|exists:client_accounts,id',
            'per_page'   => 'nullable|integer|min:10|max:100',
        ]);

        $query = FupLog::with('account.client', 'account.plan');

        if ($request->filled('account_id')) {
            $query->where('client_account_id', $request->account_id);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    /**
     * GET /api/fup/status/{account_id}
     * Get current FUP status for an account
     */
    public function status($accountId)
    {
        $account = ClientAccount::with('plan', 'radiusSessions')
            ->findOrFail($accountId);

        $fupLog = FupLog::where('client_account_id', $accountId)->first();

        if (!$account->plan->fup_limit) {
            return response()->json([
                'success' => true,
                'data'    => [
                    'enabled'       => false,
                    'message'       => 'No FUP configured for this plan',
                ],
            ]);
        }

        $bytesUsed = $fupLog?->bytes_used ?? 0;
        $fupLimitBytes = $account->plan->fup_limit * 1024 * 1024; // MB to bytes

        return response()->json([
            'success' => true,
            'data'    => [
                'enabled'         => true,
                'limit_gb'        => $account->plan->fup_limit / 1024,
                'bytes_used'      => $bytesUsed,
                'bytes_remaining' => max(0, $fupLimitBytes - $bytesUsed),
                'percentage'      => round(($bytesUsed / $fupLimitBytes) * 100, 2),
                'triggered'       => (bool) $fupLog?->triggered_at,
                'triggered_at'    => $fupLog?->triggered_at,
                'reset_at'        => $fupLog?->reset_at,
                'throttled_up'    => $account->plan->fup_speed_up,
                'throttled_down'  => $account->plan->fup_speed_down,
            ],
        ]);
    }

    /**
     * POST /api/fup/reset/{account_id}
     * Reset FUP counter for an account (admin only)
     */
    public function reset(Request $request, $accountId)
    {
        $account = ClientAccount::findOrFail($accountId);

        $fupLog = FupLog::where('client_account_id', $accountId)->first();

        if (!$fupLog) {
            $fupLog = FupLog::create([
                'client_account_id' => $accountId,
                'bytes_used'        => 0,
                'reset_at'          => now(),
            ]);
        } else {
            $fupLog->update([
                'bytes_used' => 0,
                'triggered_at' => null,
                'reset_at'   => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'FUP reset successfully',
            'data'    => $fupLog,
        ]);
    }

    /**
     * GET /api/fup/stats
     * Get FUP statistics across all accounts
     */
    public function stats()
    {
        $accountsWithFup = ClientAccount::whereHas('plan', function ($q) {
            $q->whereNotNull('fup_limit');
        })->count();

        $triggeredCount = FupLog::whereNotNull('triggered_at')->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'accounts_with_fup' => $accountsWithFup,
                'triggered_count'   => $triggeredCount,
                'percentage'        => $accountsWithFup > 0 
                    ? round(($triggeredCount / $accountsWithFup) * 100, 2)
                    : 0,
            ],
        ]);
    }
}
