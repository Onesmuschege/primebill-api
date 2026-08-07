<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkOrder\StoreWorkOrderRequest;
use App\Http\Requests\WorkOrder\UpdateWorkOrderRequest;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
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
}
