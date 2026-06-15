<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\StorePlanRequest;
use App\Http\Requests\Plan\UpdatePlanRequest;
use App\Jobs\ProvisionClientAccountJob;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Services\Plan\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PlanController extends Controller
{
    protected PlanService $planService;

    public function __construct(PlanService $planService)
    {
        $this->planService = $planService;
    }

    // GET /api/plans
    public function index(Request $request)
    {
        $plans = $this->planService->getAllPlans($request);

        return response()->json([
            'success' => true,
            'data'    => $plans,
        ]);
    }

    // POST /api/plans
    public function store(StorePlanRequest $request)
    {
        $plan = $this->planService->createPlan(
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Plan created successfully',
            'data'    => $plan,
        ], 201);
    }

    // GET /api/plans/{id}
    public function show(Plan $plan)
    {
        $plan->load('router');

        return response()->json([
            'success' => true,
            'data'    => $plan,
        ]);
    }

    // PUT /api/plans/{id}
    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $plan = $this->planService->updatePlan(
            $plan,
            $request->validated(),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Plan updated successfully',
            'data'    => $plan,
        ]);
    }

    // DELETE /api/plans/{id}
    public function destroy(Request $request, Plan $plan)
    {
        $this->planService->deletePlan($plan, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Plan deleted successfully',
        ]);
    }

    // GET /api/plans/{id}/clients
    public function clients(Plan $plan)
    {
        $clients = $plan->accounts()
            ->with('client')
            ->get()
            ->pluck('client');

        return response()->json([
            'success' => true,
            'data'    => $clients,
        ]);
    }

    // POST /api/plans/{id}/assign
    public function assign(Request $request, Plan $plan)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'username'  => 'required|string|unique:client_accounts,username',
            'password'  => 'required|string|min:6',
        ]);

        $client = Client::findOrFail($request->client_id);
        $plainPassword = $request->password;

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

        ProvisionClientAccountJob::dispatch($account->id, $plainPassword);

        return response()->json([
            'success' => true,
            'message' => 'Plan assigned and provisioning queued',
            'data'    => $account->load('plan', 'client'),
        ], 201);
    }
}
