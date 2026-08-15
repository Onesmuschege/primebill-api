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
use App\Models\WorkOrderPart;
use App\Models\WorkOrderAttachment;
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

    /**
     * POST /api/work-orders/{workOrder}/verify — closed-loop QA sign-off.
     * Only a completed work order can be verified; records who/when/notes.
     */
    public function verify(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $request->validate([
            'verification_notes' => ['nullable', 'string'],
        ]);

        if (! $workOrder->verify((int) Auth::id(), $request->input('verification_notes'))) {
            return response()->json([
                'success' => false,
                'message' => 'Only completed work orders can be verified',
            ], 422);
        }

        $workOrder->load(['verifiedBy:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Work order verified',
            'data' => $workOrder,
        ]);
    }

    /**
     * GET /api/work-orders/{workOrder}/status-history — operational timeline
     * of every lifecycle transition (scheduled → dispatched → in_progress →
     * completed → verified / cancelled).
     */
    public function statusHistory(WorkOrder $workOrder): JsonResponse
    {
        $history = $workOrder->statusHistory()
            ->with('changedBy:id,name')
            ->get();

        return response()->json(['success' => true, 'data' => $history]);
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
        // Resolve the tenant robustly: prefer the bound current tenant, fall back
        // to the authenticated user's tenant_id (Tenant::current() can be null on
        // some request paths, and an UNFILTERED User query would leak every
        // tenant's staff). This endpoint is always hit by an authed tenant user.
        $tenantId = Tenant::current()?->id ?? auth('sanctum')->user()?->tenant_id;

        $technicians = User::query()
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', ['staff', 'super_admin', 'admin']);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $result = $technicians->map(function ($tech) use ($tenantId) {
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

    /**
     * Materials used on a work order (Release 4 — field operations).
     */
    public function parts(WorkOrder $workOrder): JsonResponse
    {
        $parts = WorkOrderPart::query()
            ->where('work_order_id', $workOrder->id)
            ->with('inventoryItem')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $parts]);
    }

    public function addPart(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'part_name'         => ['required', 'string', 'max:191'],
            'part_number'       => ['nullable', 'string', 'max:191'],
            'serial_number'     => ['nullable', 'string', 'max:191'],
            'inventory_item_id' => ['nullable', 'exists:inventory_items,id'],
            'quantity'          => ['nullable', 'integer', 'min:1'],
            'unit_cost'         => ['nullable', 'numeric', 'min:0'],
            'status'            => ['nullable', Rule::In(['planned', 'ordered', 'received', 'installed', 'returned'])],
            'notes'             => ['nullable', 'string'],
        ]);

        $data['tenant_id']    = $workOrder->tenant_id;
        $data['work_order_id']= $workOrder->id;
        $data['created_by']   = Auth::id();
        $data['updated_by']   = Auth::id();

        if (isset($data['quantity'], $data['unit_cost'])) {
            $data['total_cost'] = (int) $data['quantity'] * (float) $data['unit_cost'];
        }

        $part = WorkOrderPart::create($data);

        return response()->json(['success' => true, 'message' => 'Part added', 'data' => $part], 201);
    }

    /**
     * Evidence attached to a work order (photo / document / signature / receipt).
     */
    public function attachments(WorkOrder $workOrder): JsonResponse
    {
        $attachments = WorkOrderAttachment::query()
            ->where('work_order_id', $workOrder->id)
            ->with('uploadedBy')
            ->orderByDesc('id')
            ->get();

        return response()->json(['success' => true, 'data' => $attachments]);
    }

    public function addAttachment(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'file_name'    => ['required', 'string', 'max:191'],
            'file_path'    => ['required', 'string', 'max:191'],
            'file_type'    => ['nullable', 'string', 'max:191'],
            'file_size'    => ['nullable', 'integer', 'min:0'],
            'category'     => ['nullable', Rule::In(['photo', 'document', 'signature', 'receipt'])],
            'description'  => ['nullable', 'string'],
            'metadata'     => ['nullable', 'array'],
        ]);

        $data['tenant_id']     = $workOrder->tenant_id;
        $data['work_order_id'] = $workOrder->id;
        $data['uploaded_by']   = Auth::id();

        $attachment = WorkOrderAttachment::create($data);

        return response()->json(['success' => true, 'message' => 'Evidence attached', 'data' => $attachment], 201);
    }
}
