<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrder\StoreWorkOrderRequest;
use App\Http\Requests\WorkOrder\UpdateWorkOrderRequest;
use App\Models\Client;
use App\Models\TechnicianLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use App\Services\FieldOperations\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function __construct(private WorkOrderService $workOrders) {}

    public function index(): JsonResponse
    {
        $workOrders = $this->workOrders->getAllWorkOrders(request()->all());

        return response()->json([
            'success' => true,
            'data' => $workOrders,
        ]);
    }

    public function store(StoreWorkOrderRequest $request, Client $client): JsonResponse
    {
        $workOrder = $this->workOrders->createWorkOrder($client, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Work order created successfully',
            'data' => $workOrder,
        ], 201);
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        $workOrder->load(['client', 'assignedTechnician', 'creator']);

        return response()->json([
            'success' => true,
            'data' => $workOrder,
        ]);
    }

    public function update(UpdateWorkOrderRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $workOrder = $this->workOrders->updateWorkOrder($workOrder, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Work order updated successfully',
            'data' => $workOrder,
        ]);
    }

    public function destroy(WorkOrder $workOrder): JsonResponse
    {
        $this->workOrders->deleteWorkOrder($workOrder);

        return response()->json([
            'success' => true,
            'message' => 'Work order deleted successfully',
        ]);
    }

    public function assignTechnician(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
        ]);

        $technician = User::findOrFail($request->assigned_to);
        $workOrder = $this->workOrders->assignTechnician($workOrder, $technician);

        return response()->json([
            'success' => true,
            'message' => 'Technician assigned successfully',
            'data' => $workOrder,
        ]);
    }

    public function updateStatus(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', Rule::In(['scheduled', 'dispatched', 'in_progress', 'completed', 'cancelled'])],
            'completion_notes' => ['nullable', 'string'],
            'customer_signature' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
            'completion_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'completion_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $workOrder = $this->workOrders->updateStatus($workOrder, $request->status, $request->only([
            'completion_notes',
            'customer_signature',
            'photos',
            'completion_latitude',
            'completion_longitude',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Work order status updated',
            'data' => $workOrder,
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->workOrders->getStats();

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    public function technicianWorkload(User $technician): JsonResponse
    {
        $workload = $this->workOrders->getTechnicianWorkload($technician);

                return response()->json([
            'success' => true,
            'data' => $workload,
        ]);
    }

    /**
     * GET /api/technicians
     *
     * Returns all technicians (staff users) in the current tenant with their
     * current location/status snapshot and active workload count.
     */
    public function listTechnicians(): JsonResponse
    {
        $tenant = Tenant::current();

        $technicians = User::query()
            ->when($tenant, fn($q) => $q->where('tenant_id', $tenant->id))
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', ['staff', 'super_admin', 'admin']);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $result = $technicians->map(function ($tech) use ($tenant) {
            $tenantId = $tenant?->id;

            $location = TechnicianLocation::query()
                ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                ->where('user_id', $tech->id)
                ->latest('reported_at')
                ->first();

            $workload = WorkOrder::query()
                ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                ->where('assigned_to', $tech->id)
                ->whereIn('status', ['scheduled', 'dispatched', 'in_progress'])
                ->count();

            $status = $location?->status ?? 'offline';

            return [
                'id'       => $tech->id,
                'name'     => $tech->name,
                'email'    => $tech->email,
                'status'   => $status,
                'location' => $location
                    ? ($location->latitude && $location->longitude
                        ? round((float) $location->latitude, 5) . ', ' . round((float) $location->longitude, 5)
                        : 'Office')
                    : 'Office',
                'workload' => $workload,
            ];
        });

        $statistics = [
            'total'    => $result->count(),
            'available'=> $result->where('status', 'available')->count(),
            'busy'     => $result->whereIn('status', ['busy', 'on_break'])->count(),
            'offline'  => $result->where('status', 'offline')->count(),
            'workload' => $result->sum('workload'),
        ];

        return response()->json([
            'success' => true,
            'data'    => [
                'technicians'  => $result,
                'statistics'   => $statistics,
            ],
        ]);
    }
}
