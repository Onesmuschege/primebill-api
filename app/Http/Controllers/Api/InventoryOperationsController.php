<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\StockTransfer;
use App\Services\Inventory\PurchaseOrderService;
use App\Services\Inventory\StockMovementService;
use App\Services\Inventory\StockTransferService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * InventoryOperationsController
 *
 * Dedicated workflow endpoints for the inventory engine. These are NOT
 * generic CRUD — each action advances a real business workflow:
 *
 *   Stock movements  : receive / issue / adjust / return
 *   Stock transfers  : create-draft / approve / dispatch / receive / cancel / reverse
 *   Purchase orders  : create-draft / submit / approve / receive / complete / cancel
 *
 * All service calls are tenant-scoped (via BelongsToTenant models + the
 * ResolveTenant middleware) and transactional. RBAC is enforced per-route.
 */
class InventoryOperationsController extends Controller
{
    public function __construct(
        protected StockMovementService $movements,
        protected StockTransferService $transfers,
        protected PurchaseOrderService $purchaseOrders
    ) {}

    /* ─────────────────────────── Stock movements ─────────────────────────── */

    public function receive(Request $request): JsonResponse
    {
        $data = $this->validateMovement($request, 'in');

        return $this->wrapMovement(fn () => response()->json([
            'success' => true,
            'message' => 'Stock received.',
            'data'    => $this->movements->receive($data, $request->user()->id),
        ], 201));
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $this->validateMovement($request, 'out');

        return $this->wrapMovement(fn () => response()->json([
            'success' => true,
            'message' => 'Stock issued.',
            'data'    => $this->movements->issue($data, $request->user()->id),
        ], 200));
    }

    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'warehouse_id'      => 'required|exists:warehouses,id',
            'new_quantity'      => 'required|integer|min:0',
            'notes'             => 'nullable|string',
            'metadata'          => 'nullable|array',
        ]);

        return $this->wrapMovement(fn () => response()->json([
            'success' => true,
            'message' => 'Stock adjusted.',
            'data'    => $this->movements->adjust($data, $request->user()->id),
        ]));
    }

    public function returnStock(Request $request): JsonResponse
    {
        $data = $this->validateMovement($request, 'in');

        return $this->wrapMovement(fn () => response()->json([
            'success' => true,
            'message' => 'Stock returned.',
            'data'    => $this->movements->return($data, $request->user()->id),
        ], 201));
    }

    public function balances(Request $request, string $id): JsonResponse
    {
        $item = \App\Models\InventoryItem::findOrFail((int) $id);

        return response()->json([
            'success'   => true,
            'data'      => [
                'quantity'  => $item->quantity,
                'balances'  => $this->movements->itemWarehouseBalances($item),
            ],
        ]);
    }

    /* ─────────────────────────── Stock transfers ─────────────────────────── */

    public function transferIndex(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->transfers->list($request->all()),
        ]);
    }

    public function transferStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_warehouse_id'       => 'required|exists:warehouses,id',
            'destination_warehouse_id'  => 'required|exists:warehouses,id|different:source_warehouse_id',
            'expected_date'             => 'nullable|date',
            'notes'                     => 'nullable|string',
            'metadata'                  => 'nullable|array',
            'items'                     => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity'          => 'required|integer|min:1',
            'items.*.notes'             => 'nullable|string',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Draft stock transfer created.',
            'data'    => $this->transfers->createDraft($data, $request->user()->id),
        ], 201);
    }

    public function transferShow(StockTransfer $transfer): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $transfer->load(['items', 'sourceWarehouse', 'destinationWarehouse', 'creator']),
        ]);
    }

    public function transferApprove(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->transferAction($transfer, 'approve', [$transfer, $request]);
    }

    /**
     * Generic dispatch of a transfer workflow action by name.
     */
    protected function transferAction(StockTransfer $transfer, string $action, array $args): JsonResponse
    {
        try {
            $result = match ($action) {
                'approve'   => $this->transfers->approve($transfer, $args['1']->user()->id),
                'dispatch'  => $this->transfers->dispatch($transfer, $args['1']->user()->id),
                'receive'   => $this->transfers->receive($transfer, $args['1']->user()->id),
                'cancel'    => $this->transfers->cancel($transfer, $args['1']->user()->id, $args['1']->input('reason')),
                'reverse'   => $this->transfers->reverse($transfer, $args['1']->user()->id, $args['1']->input('reason')),
                default     => throw new RuntimeException('Unknown transfer action'),
            };

            return response()->json([
                'success' => true,
                'message' => 'Transfer '.$action.'d.',
                'data'    => $result,
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['transfer' => $e->getMessage()]);
        }
    }

    public function transferDispatch(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->transferAction($transfer, 'dispatch', [$transfer, $request]);
    }

    public function transferReceive(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->transferAction($transfer, 'receive', [$transfer, $request]);
    }

    public function transferCancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->transferAction($transfer, 'cancel', [$transfer, $request]);
    }

    public function transferReverse(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->transferAction($transfer, 'reverse', [$transfer, $request]);
    }

    /* ─────────────────────────── Purchase orders ─────────────────────────── */

    public function poIndex(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->purchaseOrders->list($request->all()),
        ]);
    }

    public function poStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id'               => 'required|exists:suppliers,id',
            'warehouse_id'              => 'required|exists:warehouses,id',
            'order_date'                => 'nullable|date',
            'expected_delivery'         => 'nullable|date',
            'tax_rate'                  => 'nullable|numeric|min:0|max:100',
            'notes'                     => 'nullable|string',
            'metadata'                  => 'nullable|array',
            'items'                     => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity'          => 'required|integer|min:1',
            'items.*.unit_cost'         => 'required|numeric|min:0',
            'items.*.notes'             => 'nullable|string',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Draft purchase order created.',
            'data'    => $this->purchaseOrders->createDraft($data, $request->user()->id),
        ], 201);
    }

    public function poShow(PurchaseOrder $po): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $po->load(['items', 'supplier', 'warehouse', 'creator']),
        ]);
    }

    public function poSubmit(Request $request, PurchaseOrder $po): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->purchaseOrders->submit($po, $request->user()->id),
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['po' => $e->getMessage()]);
        }
    }

    public function poApprove(Request $request, PurchaseOrder $po): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->purchaseOrders->approve($po, $request->user()->id),
        ]);
    }

    public function poReceive(Request $request, PurchaseOrder $po): JsonResponse
    {
        $data = $request->validate([
            'items'                          => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity'               => 'required|integer|min:1',
        ]);

        try {
            return response()->json([
                'success' => true,
                'data'    => $this->purchaseOrders->receive($po, $data, $request->user()->id),
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['receive' => $e->getMessage()]);
        }
    }

    public function poComplete(Request $request, PurchaseOrder $po): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->purchaseOrders->complete($po, $request->user()->id),
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['po' => $e->getMessage()]);
        }
    }

    public function poCancel(Request $request, PurchaseOrder $po): JsonResponse
    {
        $request->validate(['reason' => 'nullable|string']);

        try {
            return response()->json([
                'success' => true,
                'data'    => $this->purchaseOrders->cancel($po, $request->user()->id, $request->input('reason')),
            ]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['po' => $e->getMessage()]);
        }
    }

    private function validateMovement(Request $request, string $direction): array
    {
        return $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'warehouse_id'      => 'required|exists:warehouses,id',
            'quantity'          => 'required|integer|min:1',
            'unit_cost'         => 'nullable|numeric|min:0',
            'reference_type'    => 'nullable|string|max:60',
            'reference_id'      => 'nullable|integer',
            'notes'             => 'nullable|string',
            'metadata'          => 'nullable|array',
        ]) + ['movement_type' => $direction];
    }

    /**
     * Convert domain errors (insufficient stock, invalid state transitions,
     * or a missing cross-tenant record) into a 422 ValidationException so
     * clients receive a well-formed validation error rather than a 500.
     */
    private function wrapMovement(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['movement' => $e->getMessage()]);
        } catch (ModelNotFoundException $e) {
            throw ValidationException::withMessages(['item' => 'The selected inventory item or warehouse was not found.']);
        }
    }
}

