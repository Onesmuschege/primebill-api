<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ActivateNetworkAccessJob;
use App\Jobs\ProvisionClientAccountJob;
use App\Jobs\PushBandwidthPolicyJob;
use App\Jobs\SuspendNetworkAccessJob;
use App\Models\ClientAccount;
use App\Models\Client;
use App\Models\SystemLog;
use App\Services\Network\ProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ClientAccountController extends Controller
{
    // POST /api/clients/{client}/accounts
    public function store(Request $request, Client $client)
    {
        $request->validate([
            'plan_id'    => 'required|exists:plans,id',
            'username'   => 'required|string|unique:client_accounts,username',
            'password'   => 'required|string|min:6',
            'type'       => 'nullable|in:prepaid,postpaid',
            'ip_address' => 'nullable|ip',
            'mac_address'=> 'nullable|string',
        ]);

        $plainPassword = $request->password;

        // Truthful provisioning state (Core ISP Gate — Section 15): the
        // service does not become ACTIVE until the network provisioning job
        // has succeeded. Claiming `active` here would hide provisioning
        // failures behind a green badge.
        $account = ClientAccount::create([
            'client_id'    => $client->id,
            'plan_id'      => $request->plan_id,
            'username'     => $request->username,
            'password'     => Hash::make($plainPassword),
            'type'         => $request->type ?? 'prepaid',
            'status'       => 'pending',
            'service_state'=> ClientAccount::STATE_PENDING,
            'ip_address'   => $request->ip_address,
            'mac_address'  => $request->mac_address,
            'expiry_date'  => now()->addDays(30),
            'activated_at' => now(),
        ]);

        // The provisioning job completes the lifecycle transition to ACTIVE
        // through the lifecycle authority after the network side succeeds.
        ProvisionClientAccountJob::dispatch($account->id, $plainPassword, $account->tenant_id);

        SystemLog::create([
            'user_id'    => $request->user()->id,
            'action'     => 'created client account',
            'model'      => 'ClientAccount',
            'model_id'   => $account->id,
            'new_values' => $request->except('password'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully',
            'data'    => $account->load('plan'),
        ], 201);
    }

    // PUT /api/clients/{client}/accounts/{account}
    public function update(Request $request, Client $client, ClientAccount $account)
    {
        $request->validate([
            'plan_id'    => 'sometimes|exists:plans,id',
            'status'     => 'sometimes|in:active,inactive,suspended,expired',
            'ip_address' => 'sometimes|nullable|ip',
            'expiry_date'=> 'sometimes|date',
        ]);

        $previousStatus = $account->status;
        $previousPlanId = $account->plan_id;

        // Lifecycle transitions must flow through the lifecycle authority —
        // the raw `status` write would silently diverge from `service_state`.
        $account->update($request->only(
            'plan_id', 'ip_address', 'expiry_date'
        ));

        // Plan change must converge the network policy on the new plan —
        // otherwise DB says 30 Mbps while RADIUS still says 10 Mbps
        // (Core ISP Gate — Section 22). The async job resolves the
        // FUP-aware effective rate and pushes it via the RADIUS backend plus
        // CoA, so live sessions pick up the new speed without a reconnect
        // where the NAS supports it.
        if ($request->filled('plan_id')
            && (int) $request->plan_id !== (int) $previousPlanId) {
            PushBandwidthPolicyJob::dispatch(
                $account->id,
                $account->tenant_id,
                'Plan changed via client account update'
            );
        }

        $newStatus = $request->input('status');

        if ($newStatus && $newStatus !== $previousStatus) {
            match ($newStatus) {
                'suspended' => SuspendNetworkAccessJob::dispatch(
                    $account->id,
                    $account->tenant_id,
                    ClientAccount::SUSPENSION_ADMIN,
                    'Account suspended via client account update'
                ),
                'active'    => ActivateNetworkAccessJob::dispatch(
                    $account->id,
                    $account->tenant_id,
                    true,
                    'Account activated via client account update'
                ),
                // 'inactive' / 'expired' are not network lifecycle states.
                default     => $account->update(['status' => $newStatus]),
            };
        }

        return response()->json([
            'success' => true,
            'message' => 'Account updated successfully',
            'data'    => $account->load('plan'),
        ]);
    }

    // DELETE /api/clients/{client}/accounts/{account}
    public function destroy(Request $request, Client $client, ClientAccount $account, ProvisioningService $provisioning)
    {
        SystemLog::create([
            'user_id'    => $request->user()->id,
            'action'     => 'deleted client account',
            'model'      => 'ClientAccount',
            'model_id'   => $account->id,
            'old_values' => $account->toArray(),
        ]);

        $provisioning->deprovisionAccount($account);
        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully',
        ]);
    }

    // GET /api/clients/{client}/accounts/{account}/status
    public function serviceStatus(Client $client, ClientAccount $account)
    {
        $account->load('plan', 'radiusSessions');

        $activeSession = $account->radiusSessions()
            ->where('status', 'active')
            ->latest('session_start')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'account_id'     => $account->id,
                'username'       => $account->username,
                'status'         => $account->status,
                'plan'           => $account->plan,
                'expiry_date'    => $account->expiry_date,
                'is_online'      => (bool) $activeSession,
                'active_session' => $activeSession,
            ],
        ]);
    }
}
