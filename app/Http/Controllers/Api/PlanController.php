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

    // GET /api/plan-templates
    // Quick-create presets for the "New Plan from Template" picker on the frontend.
    // Static for now (no dedicated table) — speeds are in Kbps, fup_limit in MB,
    // suggested_price in KES, matching the `plans` table's own units exactly so
    // the frontend can drop these straight into the plan form unconverted.
    public function templates()
    {
        $templates = [
            // ── Home Fibre (PPPoE) ──────────────────────────────────────────
            [
                'id' => 'home-bronze', 'category' => 'Home Fibre', 'name' => 'Home Bronze 5Mbps',
                'type' => 'pppoe', 'speed_up' => 5120, 'speed_down' => 5120,
                'burst_up' => 7168, 'burst_down' => 7168,
                'fup_limit' => null, 'fup_speed_up' => null, 'fup_speed_down' => null,
                'validity_days' => 30, 'suggested_price' => 1500,
            ],
            [
                'id' => 'home-silver', 'category' => 'Home Fibre', 'name' => 'Home Silver 10Mbps',
                'type' => 'pppoe', 'speed_up' => 10240, 'speed_down' => 10240,
                'burst_up' => 15360, 'burst_down' => 15360,
                'fup_limit' => null, 'fup_speed_up' => null, 'fup_speed_down' => null,
                'validity_days' => 30, 'suggested_price' => 2500,
            ],
            [
                'id' => 'home-gold', 'category' => 'Home Fibre', 'name' => 'Home Gold 20Mbps',
                'type' => 'pppoe', 'speed_up' => 20480, 'speed_down' => 20480,
                'burst_up' => 30720, 'burst_down' => 30720,
                'fup_limit' => null, 'fup_speed_up' => null, 'fup_speed_down' => null,
                'validity_days' => 30, 'suggested_price' => 3500,
            ],
            [
                'id' => 'home-platinum', 'category' => 'Home Fibre', 'name' => 'Home Platinum 40Mbps',
                'type' => 'pppoe', 'speed_up' => 40960, 'speed_down' => 40960,
                'burst_up' => 51200, 'burst_down' => 51200,
                'fup_limit' => null, 'fup_speed_up' => null, 'fup_speed_down' => null,
                'validity_days' => 30, 'suggested_price' => 5000,
            ],

            // ── Hotspot (prepaid, short-validity) ───────────────────────────
            [
                'id' => 'hotspot-hourly', 'category' => 'Hotspot', 'name' => 'Hotspot 1 Hour',
                'type' => 'hotspot', 'speed_up' => 3072, 'speed_down' => 3072,
                'burst_up' => null, 'burst_down' => null,
                'fup_limit' => 1024, 'fup_speed_up' => 512, 'fup_speed_down' => 512,
                'validity_days' => 1, 'suggested_price' => 10,
            ],
            [
                'id' => 'hotspot-daily', 'category' => 'Hotspot', 'name' => 'Hotspot Daily',
                'type' => 'hotspot', 'speed_up' => 5120, 'speed_down' => 5120,
                'burst_up' => null, 'burst_down' => null,
                'fup_limit' => 2048, 'fup_speed_up' => 1024, 'fup_speed_down' => 1024,
                'validity_days' => 1, 'suggested_price' => 50,
            ],
            [
                'id' => 'hotspot-weekly', 'category' => 'Hotspot', 'name' => 'Hotspot Weekly',
                'type' => 'hotspot', 'speed_up' => 5120, 'speed_down' => 5120,
                'burst_up' => null, 'burst_down' => null,
                'fup_limit' => 10240, 'fup_speed_up' => 1024, 'fup_speed_down' => 1024,
                'validity_days' => 7, 'suggested_price' => 200,
            ],
            [
                'id' => 'hotspot-monthly', 'category' => 'Hotspot', 'name' => 'Hotspot Monthly',
                'type' => 'hotspot', 'speed_up' => 8192, 'speed_down' => 8192,
                'burst_up' => null, 'burst_down' => null,
                'fup_limit' => 51200, 'fup_speed_up' => 2048, 'fup_speed_down' => 2048,
                'validity_days' => 30, 'suggested_price' => 1000,
            ],

            // ── Business / Dedicated (static IP, no FUP) ────────────────────
            [
                'id' => 'biz-starter', 'category' => 'Business', 'name' => 'Business Starter 10Mbps',
                'type' => 'static', 'speed_up' => 10240, 'speed_down' => 10240,
                'burst_up' => null, 'burst_down' => null,
                'fup_limit' => null, 'fup_speed_up' => null, 'fup_speed_down' => null,
                'validity_days' => 30, 'suggested_price' => 8000,
            ],
            [
                'id' => 'biz-standard', 'category' => 'Business', 'name' => 'Business Standard 25Mbps',
                'type' => 'static', 'speed_up' => 25600, 'speed_down' => 25600,
                'burst_up' => null, 'burst_down' => null,
                'fup_limit' => null, 'fup_speed_up' => null, 'fup_speed_down' => null,
                'validity_days' => 30, 'suggested_price' => 15000,
            ],
            [
                'id' => 'biz-enterprise', 'category' => 'Business', 'name' => 'Business Enterprise 50Mbps',
                'type' => 'static', 'speed_up' => 51200, 'speed_down' => 51200,
                'burst_up' => null, 'burst_down' => null,
                'fup_limit' => null, 'fup_speed_up' => null, 'fup_speed_down' => null,
                'validity_days' => 30, 'suggested_price' => 28000,
            ],
        ];

        return response()->json([
            'success' => true,
            'data'    => $templates,
        ]);
    }
}
