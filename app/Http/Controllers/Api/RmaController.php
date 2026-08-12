<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rma;
use App\Services\Inventory\RmaService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * RMA (Return / Replacement / Repair Authorisation) API.
 *
 * Routes are mounted under the shared ISP api group with explicit
 * auth:sanctum + tenant + permission gating, mirroring WorkOrderController.
 */
class RmaController extends BaseController
{
    public function __construct(protected RmaService $rmas)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->rmas->getAll($request->all()));
    }

    public function store(Request $request)
    {
        $data = Validator::make($request->all(), $this->rules())->validate();

        $rma = $this->rmas->createRma($data);

        return response()->json($rma, 201);
    }

    public function show(Rma $rma)
    {
        return response()->json($rma);
    }

    public function update(Request $request, Rma $rma)
    {
        $data = Validator::make($request->all(), $this->updateRules())->validate();

        return response()->json($this->rmas->updateRma($rma, $data));
    }

    public function destroy(Rma $rma)
    {
        $this->rmas->deleteRma($rma);

        return response()->noContent();
    }

    public function stats()
    {
        return response()->json($this->rmas->getStats());
    }

    // ── Workflow transitions ────────────────────────────────────────────────
    public function approve(Request $request, Rma $rma)
    {
        try {
            return response()->json($this->rmas->approve($rma, $request->input('notes')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, Rma $rma)
    {
        try {
            return response()->json($this->rmas->reject($rma, $request->input('reason')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function process(Request $request, Rma $rma)
    {
        try {
            return response()->json($this->rmas->process($rma, $request->input('tracking_number')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function complete(Request $request, Rma $rma)
    {
        try {
            return response()->json($this->rmas->complete($rma, $request->input('tracking_number')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, Rma $rma)
    {
        try {
            return response()->json($this->rmas->cancel($rma, $request->input('reason')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function rules(): array
    {
        return [
            'inventory_item_id'      => ['nullable', 'exists:inventory_items,id'],
            'customer_equipment_id'  => ['nullable', 'exists:customer_equipment,id'],
            'client_id'              => ['nullable', 'exists:clients,id'],
            'supplier_id'            => ['nullable', 'exists:suppliers,id'],
            'work_order_id'          => ['nullable', 'exists:work_orders,id'],
            'type'                   => ['nullable', 'in:' . implode(',', Rma::types())],
            'priority'               => ['nullable', 'in:' . implode(',', Rma::priorities())],
            'status'                 => ['nullable', 'in:' . implode(',', Rma::statuses())],
            'reason'                 => ['nullable', 'string'],
            'description'            => ['nullable', 'string'],
            'notes'                  => ['nullable', 'string'],
            'tracking_number'        => ['nullable', 'string'],
            'expected_return_at'     => ['nullable', 'date'],
        ];
    }

    protected function updateRules(): array
    {
        return [
            'type'                   => ['sometimes', 'in:' . implode(',', Rma::types())],
            'priority'               => ['sometimes', 'in:' . implode(',', Rma::priorities())],
            'status'                 => ['sometimes', 'in:' . implode(',', Rma::statuses())],
            'reason'                 => ['sometimes', 'nullable', 'string'],
            'description'            => ['sometimes', 'nullable', 'string'],
            'notes'                  => ['sometimes', 'nullable', 'string'],
            'tracking_number'        => ['sometimes', 'nullable', 'string'],
            'expected_return_at'     => ['sometimes', 'nullable', 'date'],
        ];
    }
}
