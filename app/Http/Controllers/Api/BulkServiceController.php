<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PushBandwidthPolicyJob;
use App\Models\ClientAccount;
use App\Models\MikrotikSyncLog;
use App\Models\Plan;
use App\Services\Network\ProvisioningService;
use App\Services\Network\ServiceLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BulkServiceController — Section 66 bulk operations.
 *
 * Bulk actions are:
 *   - tenant-safe (every lookup goes through the tenant global scope)
 *   - permission-controlled at the route level
 *   - per-record isolated (one failure never stops the remaining records)
 *   - reported per-record success/failure
 *   - routed through the SAME authoritative lifecycle/provisioning pipeline
 *     as single-service operations — admin bulk is not a bypass (Section 11).
 */
class BulkServiceController extends Controller
{
    public function __construct(
        protected ServiceLifecycleService $lifecycle,
        protected ProvisioningService $provisioning
    ) {}

    protected const MAX_IDS = 500;

    /**
     * Validate the shared batch payload.
     */
    protected function accountIds(Request $request): array
    {
        $validated = $request->validate([
            'account_ids'   => 'required|array|min:1|max:' . self::MAX_IDS,
            'account_ids.*' => 'integer|distinct',
            'reason'        => 'nullable|string|max:255',
        ]);

        return array_map('intval', $validated['account_ids']);
    }

    protected function reason(Request $request, string $fallback): string
    {
        return $request->input('reason', $fallback);
    }
/**
     * POST /api/network/services/bulk/suspend
     */
    public function bulkSuspend(Request $request): JsonResponse
    {
        $ids = $this->accountIds($request);
        $reason = $this->reason($request, 'Bulk administrative suspension');

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $account = ClientAccount::findOrFail($id);

                DB::transaction(function () use ($account, $reason, $request) {
                    $this->lifecycle->suspend(
                        $account,
                        $reason,
                        ServiceLifecycleService::SUSPENSION_ADMIN,
                        $request->user()?->id
                    );
                });

                $results[] = [
                    'account_id'    => $id,
                    'success'       => true,
                    'service_state' => $account->fresh()->service_state,
                ];
                $succeeded++;
            } catch (\Throwable $e) {
                $results[] = [
                    'account_id' => $id,
                    'success'    => false,
                    'error'      => $e->getMessage(),
                ];
                $failed++;
            }
        }

        return response()->json([
            'success'   => true,
            'operation' => 'suspend',
            'requested' => count($ids),
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }

    /**
     * POST /api/network/services/bulk/restore
     *
     * Explicit administrative restore — the only operation that lifts an
     * administrative hold (SL2). Reconciliation never does this.
     */
    public function bulkRestore(Request $request): JsonResponse
    {
        $ids = $this->accountIds($request);
        $reason = $this->reason($request, 'Bulk administrative restoration');

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $account = ClientAccount::findOrFail($id);

                DB::transaction(function () use ($account, $reason, $request) {
                    $this->lifecycle->activate(
                        $account,
                        $reason,
                        true,
                        $request->user()?->id
                    );
                });

                $results[] = [
                    'account_id'    => $id,
                    'success'       => true,
                    'service_state' => $account->fresh()->service_state,
                ];
                $succeeded++;
            } catch (\Throwable $e) {
                $results[] = [
                    'account_id' => $id,
                    'success'    => false,
                    'error'      => $e->getMessage(),
                ];
                $failed++;
            }
        }

        return response()->json([
            'success'   => true,
            'operation' => 'restore',
            'requested' => count($ids),
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }
/**
     * POST /api/network/services/bulk/activate
     */
    public function bulkActivate(Request $request): JsonResponse
    {
        $ids = $this->accountIds($request);
        $reason = $this->reason($request, 'Bulk activation');

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $account = ClientAccount::findOrFail($id);

                DB::transaction(function () use ($account, $reason, $request) {
                    $this->lifecycle->activate(
                        $account,
                        $reason,
                        true,
                        $request->user()?->id
                    );
                });

                $results[] = [
                    'account_id'    => $id,
                    'success'       => true,
                    'service_state' => $account->fresh()->service_state,
                ];
                $succeeded++;
            } catch (\Throwable $e) {
                $results[] = [
                    'account_id' => $id,
                    'success'    => false,
                    'error'      => $e->getMessage(),
                ];
                $failed++;
            }
        }

        return response()->json([
            'success'   => true,
            'operation' => 'activate',
            'requested' => count($ids),
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }

    /**
     * POST /api/network/services/bulk/plan-change
     *
     * Each account gets the SAME authoritative plan-change pipeline as a
     * single-service change: plan_id updated in a transaction, then a
     * PushBandwidthPolicyJob converges the RADIUS/CoA policy on the new
     * plan — resolving the FUP-aware effective rate (Section 22/23).
     */
    public function bulkPlanChange(Request $request): JsonResponse
    {
        $ids = $this->accountIds($request);

        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $reason = $this->reason($request, "Bulk plan change to {$plan->name}");

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $account = ClientAccount::findOrFail($id);

                DB::transaction(function () use ($account, $plan) {
                    $account->update(['plan_id' => $plan->id]);
                });

                // Converge the network policy on the new plan (async, so the
                // local transaction closes before any remote call — Section 60).
                PushBandwidthPolicyJob::dispatch(
                    $account->id,
                    $account->tenant_id,
                    $reason
                );

                $results[] = [
                    'account_id'  => $id,
                    'success'     => true,
                    'plan_id'     => $plan->id,
                    'policy_job'  => 'queued',
                ];
                $succeeded++;
            } catch (\Throwable $e) {
                $results[] = [
                    'account_id' => $id,
                    'success'    => false,
                    'error'      => $e->getMessage(),
                ];
                $failed++;
            }
        }

        return response()->json([
            'success'   => true,
            'operation' => 'plan-change',
            'plan_id'   => $plan->id,
            'requested' => count($ids),
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }
/**
     * POST /api/network/services/bulk/retry-provisioning
     *
     * Re-runs the authoritative provisioning pipeline for accounts whose last
     * provisioning attempt failed, so an operator can heal a batch of failed
     * activations without touching the CLI (or specifying a subset via
     * account_ids).
     */
    public function bulkRetryProvisioning(Request $request): JsonResponse
    {
        $query = MikrotikSyncLog::query()
            ->whereIn('status', ['failed', 'partial'])
            ->where('created_at', '>=', now()->subDay());

        $requestedIds = $request->input('account_ids');

        if (is_array($requestedIds) && count($requestedIds)) {
            $query->whereIn('client_account_id', array_map('intval', $requestedIds));
        }

        $accountIds = $query
            ->distinct()
            ->get(['client_account_id'])
            ->pluck('client_account_id')
            ->filter()
            ->unique()
            ->values()
            ->take(self::MAX_IDS)
            ->all();

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($accountIds as $id) {
            try {
                $account = ClientAccount::findOrFail($id);

                // provisionAccount is idempotent and restores the desired
                // user/policy state on both RADIUS and the router.
                $ok = $this->provisioning->provisionAccount(
                    $account,
                    $account->password ?: 'not-used'
                );

                $results[] = [
                    'account_id' => $id,
                    'success'    => $ok,
                    'error'      => $ok ? null : 'provisioning still failing',
                ];
                $ok ? $succeeded++ : $failed++;
            } catch (\Throwable $e) {
                $results[] = [
                    'account_id' => $id,
                    'success'    => false,
                    'error'      => $e->getMessage(),
                ];
                $failed++;
            }
        }

        return response()->json([
            'success'   => true,
            'operation' => 'retry-provisioning',
            'requested' => count($accountIds),
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }
}