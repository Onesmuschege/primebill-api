<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ActivateNetworkAccessJob;
use App\Jobs\ProvisionClientAccountJob;
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

        $account = ClientAccount::create([
            'client_id'    => $client->id,
            'plan_id'      => $request->plan_id,
            'username'     => $request->username,
            'password'     => Hash::make($plainPassword),
            'type'         => $request->type ?? 'prepaid',
            'status'       => 'active',
            'ip_address'   => $request->ip_address,
            'mac_address'  => $request->mac_address,
            'expiry_date'  => now()->addDays(30),
            'activated_at' => now(),
        ]);

        ProvisionClientAccountJob::dispatch($account->id, $plainPassword);

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

        $account->update($request->only(
            'plan_id', 'status', 'ip_address', 'expiry_date'
        ));

        if ($request->filled('status') && $request->status !== $previousStatus) {
            match ($request->status) {
                'suspended' => SuspendNetworkAccessJob::dispatch($account->id),
                'active'    => ActivateNetworkAccessJob::dispatch($account->id),
                default     => null,
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
