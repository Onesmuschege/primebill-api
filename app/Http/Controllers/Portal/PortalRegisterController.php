<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionClientAccountJob;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\SystemLog;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PortalRegisterController extends Controller
{
    use ApiResponse;

    // POST /api/portal/register
    public function register(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:clients,email',
            'phone'      => 'required|string|max:20|unique:clients,phone',
            'id_number'  => 'nullable|string|max:20',
            'address'    => 'nullable|string|max:255',
            'town'       => 'nullable|string|max:100',
            'plan_id'    => 'required|exists:plans,id',
            'username'   => 'required|string|min:4|unique:client_accounts,username',
            'password'   => ['required', 'confirmed', Password::min(6)],
        ]);

        $plan = Plan::where('id', $request->plan_id)->where('is_active', true)->first();

        if (!$plan) {
            return $this->error('Selected plan is not available.', null, 422);
        }

        $plainPassword = $request->password;

        $result = DB::transaction(function () use ($request, $plan, $plainPassword) {
            $client = Client::create([
                'first_name' => $request->first_name,
                'last_name'  => $request->last_name,
                'email'      => $request->email,
                'phone'      => $request->phone,
                'id_number'  => $request->id_number,
                'address'    => $request->address,
                'town'       => $request->town,
                'status'     => 'active',
            ]);

            $account = ClientAccount::create([
                'client_id'    => $client->id,
                'plan_id'      => $plan->id,
                'username'     => $request->username,
                'password'     => Hash::make($plainPassword),
                'type'         => 'prepaid',
                'status'       => 'active',
                'expiry_date'  => now()->addDays($plan->validity_days ?? 30),
                'activated_at' => now(),
            ]);

            return compact('client', 'account');
        });

        ProvisionClientAccountJob::dispatch($result['account']->id, $plainPassword);

        SystemLog::create([
            'action'     => 'portal self-registration',
            'model'      => 'Client',
            'model_id'   => $result['client']->id,
            'ip_address' => $request->ip(),
            'new_values' => $request->except('password', 'password_confirmation'),
        ]);

        return $this->success([
            'client_id' => $result['client']->id,
            'username'  => $result['account']->username,
            'plan'      => $plan->only(['id', 'name', 'price']),
        ], 'Registration successful. You can now log in to the portal.', 201);
    }
}
