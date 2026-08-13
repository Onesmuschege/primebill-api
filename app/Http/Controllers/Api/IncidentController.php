<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NetworkIncident;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — Incident / Outage Management controller.
 *
 * CRUD + lifecycle for network incidents.
 */
class IncidentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = NetworkIncident::query()
            ->with(['affectedDevice:id,name,ip_address', 'affectedOlt:id,name', 'affectedPonPort:id,name', 'creator', 'assignedTechnician', 'acknowledgedByUser', 'resolvedByUser', 'escalatedByUser']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('root_cause', 'like', "%{$search}%");
            });
        }

        $incidents = $query->orderByDesc('detected_at')->paginate($request->input('per_page', 25));

        return $this->success($incidents);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['nullable', Rule::in(['detected', 'acknowledged', 'investigating', 'mitigating', 'resolved', 'closed'])],
            'detected_at' => 'nullable|date',
            'affected_device_id' => 'nullable|exists:routers,id',
            'affected_olt_id' => 'nullable|exists:olts,id',
            'affected_pon_port_id' => 'nullable|exists:pon_ports,id',
            'assigned_to' => 'nullable|exists:users,id',
            'root_cause' => 'nullable|string',
            'resolution' => 'nullable|string',
            'affected_services' => 'nullable|array',
            'affected_customers_count' => 'nullable|integer|min:0',
        ]);

        $incident = NetworkIncident::create(array_merge($data, [
            'created_by' => $request->user()->id,
            'detected_at' => $data['detected_at'] ?? now(),
            'status' => $data['status'] ?? 'detected',
        ]));

        $incident->load(['affectedDevice:id,name,ip_address', 'affectedOlt:id,name', 'affectedPonPort:id,name', 'creator', 'assignedTechnician']);

        return $this->success($incident, 'Incident created', 201);
    }

    public function show(Request $request, NetworkIncident $incident): JsonResponse
    {
        $incident->load([
            'affectedDevice:id,name,ip_address',
            'affectedOlt:id,name',
            'affectedPonPort:id,name',
            'creator:id,name',
            'assignedTechnician:id,name',
            'acknowledgedByUser:id,name',
            'resolvedByUser:id,name',
            'escalatedByUser:id,name',
        ]);

        return $this->success($incident);
    }

    public function update(Request $request, NetworkIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'severity' => ['sometimes', Rule::In(['low', 'medium', 'high', 'critical'])],
            'affected_device_id' => 'nullable|exists:routers,id',
            'affected_olt_id' => 'nullable|exists:olts,id',
            'affected_pon_port_id' => 'nullable|exists:pon_ports,id',
            'assigned_to' => 'nullable|exists:users,id',
            'root_cause' => 'nullable|string',
            'resolution' => 'nullable|string',
            'affected_services' => 'nullable|array',
            'affected_customers_count' => 'nullable|integer|min:0',
        ]);

        $incident->update($data);
        $incident->refresh();

        $incident->load([
            'affectedDevice:id,name,ip_address',
            'affectedOlt:id,name',
            'affectedPonPort:id,name',
            'creator:id,name',
            'assignedTechnician:id,name',
            'acknowledgedByUser:id,name',
            'resolvedByUser:id,name',
        ]);

        return $this->success($incident, 'Incident updated');
    }

    public function destroy(Request $request, NetworkIncident $incident): JsonResponse
    {
        $incident->delete();

        return $this->success(null, 'Incident deleted');
    }

    public function acknowledge(Request $request, NetworkIncident $incident): JsonResponse
    {
        if (!$incident->transitionTo('acknowledged')) {
            return $this->error('Invalid transition', null, 422);
        }

        $incident->update(['acknowledged_by' => $request->user()->id]);
        $incident->refresh();

        return $this->success($incident, 'Incident acknowledged');
    }

    public function updateStatus(Request $request, NetworkIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::In(['detected', 'acknowledged', 'investigating', 'mitigating', 'resolved', 'closed'])],
        ]);

        if (!$incident->transitionTo($data['status'])) {
            return $this->error('Invalid transition', null, 422);
        }

        if ($data['status'] === 'resolved') {
            $incident->update(['resolved_by' => $request->user()->id]);
        }

        $incident->refresh();

        return $this->success($incident, 'Incident status updated');
    }

    public function resolve(Request $request, NetworkIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'resolution' => 'required|string',
            'root_cause' => 'nullable|string',
        ]);

        $incident->update([
            'resolution' => $data['resolution'],
            'root_cause' => $data['root_cause'] ?? $incident->root_cause,
            'resolved_by' => $request->user()->id,
        ]);

        if (!$incident->transitionTo('resolved')) {
            return $this->error('Invalid transition', null, 422);
        }

        $incident->refresh();

        return $this->success($incident, 'Incident resolved');
    }

    public function close(Request $request, NetworkIncident $incident): JsonResponse
    {
        if (!$incident->transitionTo('closed')) {
            return $this->error('Invalid transition', null, 422);
        }

        if (!$incident->closed_at) {
            $incident->update(['closed_at' => now()]);
        }

        $incident->refresh();

        return $this->success($incident, 'Incident closed');
    }

    public function escalate(Request $request, NetworkIncident $incident): JsonResponse
    {
        $data = $request->validate([
            'escalation_reason' => ['required', 'string'],
            'severity' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
        ]);

        if (! $incident->escalate($request->user()->id, $data['escalation_reason'], $data['severity'] ?? null)) {
            return $this->error('Resolved/closed incidents cannot be escalated', null, 422);
        }

        $incident->load(['escalatedByUser:id,name']);

        return $this->success($incident, 'Incident escalated');
    }

    public function stats(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $stats = [
            'open_incidents' => NetworkIncident::where('tenant_id', $tenantId)->open()->count(),
            'critical_incidents' => NetworkIncident::where('tenant_id', $tenantId)->open()->where('severity', 'critical')->count(),
            'resolved_today' => NetworkIncident::where('tenant_id', $tenantId)->whereDate('resolved_at', today())->count(),
            'avg_resolution_minutes' => NetworkIncident::where('tenant_id', $tenantId)
                ->whereNotNull('duration_minutes')
                ->avg('duration_minutes'),
            'by_severity' => NetworkIncident::where('tenant_id', $tenantId)
                ->select('severity', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity'),
            'by_status' => NetworkIncident::where('tenant_id', $tenantId)
                ->select('status', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status'),
        ];

        return $this->success($stats);
    }
}
