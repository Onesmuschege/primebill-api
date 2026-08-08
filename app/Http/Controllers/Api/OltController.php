<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PonPort;
use App\Services\Olt\OltService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 — Fiber / OLT Management controller.
 *
 * CRUD for OLTs, PON ports, ONTs, and fiber infrastructure, plus ONT
 * registration and signal polling delegated to the vendor-agnostic OltService.
 */
class OltController extends Controller
{
    use ApiResponse;

    public function __construct(protected OltService $olts)
    {
    }

    // ── OLTs ───────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $olts = Olt::query()
            ->withCount('ponPorts')
            ->withCount('onts')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('vendor'), fn ($q, $v) => $q->where('vendor', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return $this->success($olts);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:191',
            'vendor'        => 'sometimes|in:huawei,zte,fiberhome,vsol,other',
            'model'         => 'sometimes|nullable|string|max:191',
            'ip_address'    => 'required|ip',
            'username'      => 'sometimes|nullable|string|max:191',
            'password'      => 'sometimes|nullable|string|max:191',
            'snmp_community'=> 'sometimes|nullable|string|max:191',
            'status'        => 'sometimes|in:online,offline,maintenance',
            'location'      => 'sometimes|nullable|string|max:191',
            'location_lat'  => 'sometimes|nullable|numeric',
            'location_lng'  => 'sometimes|nullable|numeric',
        ]);

        $olt = Olt::create($data);

        return $this->success($olt, 'OLT created', 201);
    }

    public function show(Olt $olt)
    {
        $olt->loadCount('ponPorts')
            ->loadCount('onts')
            ->load([
                'ponPorts' => fn ($q) => $q->withCount('onts'),
                'onts' => fn ($q) => $q->with('clientAccount:id,username'),
            ]);

        return $this->success($olt);
    }

    public function update(Request $request, Olt $olt)
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:191',
            'vendor'        => 'sometimes|in:huawei,zte,fiberhome,vsol,other',
            'model'         => 'sometimes|nullable|string|max:191',
            'ip_address'    => 'sometimes|ip',
            'username'      => 'sometimes|nullable|string|max:191',
            'password'      => 'sometimes|nullable|string|max:191',
            'snmp_community'=> 'sometimes|nullable|string|max:191',
            'status'        => 'sometimes|in:online,offline,maintenance',
            'location'      => 'sometimes|nullable|string|max:191',
            'location_lat'  => 'sometimes|nullable|numeric',
            'location_lng'  => 'sometimes|nullable|numeric',
        ]);

        $olt->update($data);

        return $this->success($olt, 'OLT updated');
    }

    public function destroy(Olt $olt)
    {
        $olt->delete();

        return $this->success(null, 'OLT deleted');
    }

    public function testConnection(Olt $olt)
    {
        $ok = $this->olts->testConnection($olt);

        return $ok
            ? $this->success(['connected' => true], 'Connection successful')
            : $this->error('Connection failed', null, 422);
    }

    // ── PON Ports ──────────────────────────────────────────────────────────

    public function ponPorts(Request $request, Olt $olt)
    {
        $ports = $olt->ponPorts()
            ->withCount('onts')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')
            ->paginate($request->input('per_page', 25));

        return $this->success($ports);
    }

    public function storePonPort(Request $request, Olt $olt)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:191',
            'technology' => 'sometimes|in:gpon,xgpon,xgs-pon',
            'status'     => 'sometimes|in:active,inactive,faulty',
            'max_onts'   => 'sometimes|integer|min:1|max:512',
        ]);

        $port = $olt->ponPorts()->create($data);

        return $this->success($port, 'PON port created', 201);
    }

    // ── ONTs ───────────────────────────────────────────────────────────────

    public function onts(Request $request, Olt $olt)
    {
        $onts = $olt->onts()
            ->with(['clientAccount:id,username', 'ponPort:id,name'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), function ($q, $v) {
                $q->where('serial', 'like', "%{$v}%")
                  ->orWhere('mac_address', 'like', "%{$v}%");
            })
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 25));

        return $this->success($onts);
    }

    public function storeOnt(Request $request, Olt $olt)
    {
        $data = $request->validate([
            'pon_port_id'         => 'required|exists:pon_ports,id',
            'serial'              => 'required|string|max:191',
            'mac_address'         => 'sometimes|nullable|string|max:191',
            'vendor'              => 'sometimes|nullable|string|max:191',
            'model'               => 'sometimes|nullable|string|max:191',
            'firmware'            => 'sometimes|nullable|string|max:191',
            'ont_form'            => 'sometimes|nullable|string|max:191',
            'client_account_id'   => 'sometimes|nullable|exists:client_accounts,id',
        ]);

        $ont = $this->olts->registerOnt($olt, (int) $data['pon_port_id'], $data);

        return $this->success($ont->load(['ponPort', 'clientAccount']), 'ONT registered', 201);
    }

    public function showOnt(Ont $ont)
    {
        $ont->load(['olt', 'ponPort', 'clientAccount']);

        return $this->success($ont);
    }

    public function updateOnt(Request $request, Ont $ont)
    {
        $data = $request->validate([
            'mac_address'       => 'sometimes|nullable|string|max:191',
            'model'             => 'sometimes|nullable|string|max:191',
            'firmware'          => 'sometimes|nullable|string|max:191',
            'status'            => 'sometimes|in:online,offline,provisioning,faulty',
            'client_account_id' => 'sometimes|nullable|exists:client_accounts,id',
        ]);

        $ont->update($data);

        return $this->success($ont, 'ONT updated');
    }

    public function destroyOnt(Olt $olt, Ont $ont)
    {
        $this->olts->removeOnt($olt, $ont);

        return $this->success(null, 'ONT removed');
    }

    public function pollSignal(Olt $olt)
    {
        $result = $this->olts->pollAllOntSignals($olt);

        return $this->success($result, 'ONT signal polled');
    }
}
