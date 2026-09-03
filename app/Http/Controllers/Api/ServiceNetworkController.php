<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\RadiusControlLog;
use App\Models\RadiusSession;
use App\Services\Network\AccessMethodManager;
use App\Services\Network\ServiceLifecycleService;
use App\Services\Radius\RadiusAdapterInterface;
use App\Services\Radius\RadiusControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceNetworkController extends Controller
{
    public function __construct(
        protected ServiceLifecycleService $lifecycle,
        protected AccessMethodManager $accessMethods,
        protected RadiusControlService $radiusControl
    ) {}

    /**
     * Get the network status of a service.
     */
    public function status(int $accountId): JsonResponse
    {
        $account = ClientAccount::with(['plan', 'nas', 'serviceProfile'])
            ->findOrFail($accountId);

        $activeSessions = $account->radiusSessions()
            ->where('status', RadiusSession::STATUS_ONLINE)
            ->get();

        $recentControlLogs = $account->radiusControlLogs()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'account'           => $account,
            'is_entitled'       => $account->isEntitled(),
            'service_state'     => $account->service_state,
            'suspension_type'   => $account->suspension_type,
            'administrative_hold' => $account->hasAdministrativeHold(),
            'access_method'     => $account->access_method,
            'rate_limit_policy' => $account->rate_limit_policy,
            'active_sessions'   => $activeSessions,
            'recent_control_logs'=> $recentControlLogs,
        ]);
    }

    /**
     * Suspend a service (operator action → administrative hold, SL2).
     */
    public function suspend(int $accountId, Request $request): JsonResponse
    {
        $account = ClientAccount::findOrFail($accountId);

        $reason = $request->input('reason', 'Manual suspension');

        DB::transaction(function () use ($account, $reason, $request) {
            $this->lifecycle->suspend(
                $account,
                $reason,
                ServiceLifecycleService::SUSPENSION_ADMIN,
                $request->user()?->id
            );
        });

        $fresh = $account->fresh();

        return response()->json([
            'message'             => 'Service suspended',
            'account_id'          => $account->id,
            'service_state'       => $fresh->service_state,
            'suspension_type'     => $fresh->suspension_type,
            'administrative_hold' => $fresh->hasAdministrativeHold(),
            'is_entitled'         => $fresh->isEntitled(),
        ]);
    }

    /**
     * Restore (activate) a service.
     *
     * This is an explicit administrative restore — the only operation that
     * may lift an administrative hold (SL2). The reported entitlement is
     * computed truthfully, never fabricated.
     */
    public function restore(int $accountId, Request $request): JsonResponse
    {
        $account = ClientAccount::findOrFail($accountId);

        $reason = $request->input('reason', 'Manual restoration');

        DB::transaction(function () use ($account, $reason, $request) {
            $this->lifecycle->activate(
                $account,
                $reason,
                true,
                $request->user()?->id
            );
        });

        $fresh = $account->fresh();

        return response()->json([
            'message'             => 'Service restored',
            'account_id'          => $account->id,
            'service_state'       => $fresh->service_state,
            'suspension_type'     => $fresh->suspension_type,
            'administrative_hold' => $fresh->hasAdministrativeHold(),
            'is_entitled'         => $fresh->isEntitled(),
        ]);
    }

    /**
     * Disconnect a specific session or all sessions for a service.
     */
    public function disconnect(int $accountId, Request $request): JsonResponse
    {
        $account = ClientAccount::findOrFail($accountId);

        $sessionId = $request->input('session_id');
        $accessMethod = $this->accessMethods->resolve($account);

        $result = $accessMethod->disconnectSession($account, $sessionId);

        return response()->json([
            'message'     => $result
                ? 'Active sessions disconnected. The credential remains provisioned.'
                : 'Failed to disconnect sessions. Check router reachability and retry.',
            'account_id'  => $account->id,
            'session_id'  => $sessionId,
            'success'     => $result,
        ]);
    }

    /**
     * Send a CoA (Change of Authorization) to change bandwidth or policy.
     */
    public function coa(int $accountId, Request $request): JsonResponse
    {
        $account = ClientAccount::findOrFail($accountId);

        $validated = $request->validate([
            'download_speed' => 'nullable|numeric|min:0',
            'upload_speed'   => 'nullable|numeric|min:0',
            'session_timeout'=> 'nullable|integer|min:0',
            'idle_timeout'   => 'nullable|integer|min:0',
        ]);

        $policy = [];
        if ($validated['download_speed'] ?? null) {
            $policy['download_speed'] = $validated['download_speed'];
        }
        if ($validated['upload_speed'] ?? null) {
            $policy['upload_speed'] = $validated['upload_speed'];
        }
        if ($validated['session_timeout'] ?? null) {
            $policy['session_timeout'] = $validated['session_timeout'];
        }
        if ($validated['idle_timeout'] ?? null) {
            $policy['idle_timeout'] = $validated['idle_timeout'];
        }

        if (empty($policy)) {
            $policy = [
                'download_speed' => $account->plan->speed_down ?? 1024,
                'upload_speed'   => $account->plan->speed_up ?? 512,
            ];
        }

        $result = $this->radiusControl->applyPolicy($account, $policy);

        return response()->json([
            'message'            => $result
                ? 'Bandwidth policy updated on the RADIUS backend. The active session will pick it up on next reconnect.'
                : 'Failed to update the bandwidth policy on the RADIUS backend.',
            'account_id'         => $account->id,
            'policy'             => $policy,
            'success'            => $result,
            'coa_supported'      => false,
            'requires_reconnect' => true,
        ]);
    }
}
