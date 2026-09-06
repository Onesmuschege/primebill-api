<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionClientAccountJob;
use App\Models\ClientAccount;
use App\Models\MikrotikSyncLog;
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
/**
     * GET /api/services/{account}/provisioning-status
     *
     * Phase 7: provisioning status + retry. Returns the service's real
     * provisioning posture — structured MikrotikSyncLog audit rows (each with
     * its idempotency key), the lifecycle state, and whether the account is
     * eligible for a retry. Nothing here is fabricated; it is the raw audit
     * trail of the single-authority ProvisioningService.
     */
    public function provisioningStatus(int $accountId): JsonResponse
    {
        $account = ClientAccount::with('plan')->findOrFail($accountId);

        $logs = MikrotikSyncLog::where('client_account_id', $accountId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (MikrotikSyncLog $log) => [
                'id'              => $log->id,
                'operation'       => $log->operation,
                'status'          => $log->status,
                'router_ok'       => $log->router_ok,
                'radius_ok'       => $log->radius_ok,
                'failure_reason'  => $log->failure_reason,
                'attempts'        => (int) $log->attempts,
                'idempotency_key' => $log->idempotency_key,
                'log_message'     => $log->log_message,
                'created_at'      => $log->created_at?->toISOString(),
            ])
            ->values()
            ->toArray();

        $latest = $logs[0] ?? null;

        $lastProvision = MikrotikSyncLog::where('client_account_id', $accountId)
            ->where('operation', 'provision')
            ->orderByDesc('created_at')
            ->first();

        $retryable = in_array($account->service_state, [
            ClientAccount::STATE_PENDING,
            ClientAccount::STATE_PROVISIONING,
        ], true);

        return response()->json([
            'account' => [
                'id'             => $account->id,
                'username'       => $account->username,
                'service_state'  => $account->service_state,
                'is_entitled'    => $account->isEntitled(),
                'provisioned_at' => $account->provisioned_at?->toISOString(),
                'plan'           => $account->plan?->name,
            ],
            'latest_status' => $latest,
            'last_provision' => $lastProvision ? [
                'status'          => $lastProvision->status,
                'idempotency_key' => $lastProvision->idempotency_key,
                'created_at'      => $lastProvision->created_at?->toISOString(),
            ] : null,
            'retryable'    => $retryable,
            'attempts'     => $lastProvision ? (int) $lastProvision->attempts : 0,
            'logs'         => $logs,
        ]);
    }
/**
     * POST /api/services/{account}/provisioning-retry
     *
     * Re-enqueue ProvisionClientAccountJob for a stuck service (PENDING /
     * PROVISIONING). Each manual retry mints a FRESH idempotency key, so the
     * queue inherits the same duplicate-protection as the original dispatch
     * while never being short-circuited by an earlier failed attempt's key.
     */
    public function retryProvisioning(int $accountId, Request $request): JsonResponse
    {
        $account = ClientAccount::findOrFail($accountId);

        if (! in_array($account->service_state, [
            ClientAccount::STATE_PENDING,
            ClientAccount::STATE_PROVISIONING,
        ], true)) {
            return response()->json([
                'message'       => "Account is {$account->service_state} — not eligible for a provisioning retry.",
                'account_id'    => $account->id,
                'service_state' => $account->service_state,
            ], 422);
        }

        $freshKey = 'retry:provision:'.$account->id.':'.now()->format('YmdHis');
        $tenantId = $account->tenant_id;

        ProvisionClientAccountJob::dispatch(
            $account->id,
            $request->input('password', ''),
            $tenantId,
            $freshKey
        );

        return response()->json([
            'message'         => 'Provisioning retry queued',
            'account_id'      => $account->id,
            'service_state'   => $account->service_state,
            'idempotency_key' => $freshKey,
            'queued'          => true,
        ]);
    }
}
