<?php

namespace App\Services\Inventory;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PurchaseOrderService
 *
 * Procurement / purchasing workflow engine.
 *
 * State machine:
 *   draft → submitted → approved → partially_received → received → completed
 *   draft → cancelled
 *   submitted → cancelled
 *
 * Receiving a PO updates inventory (inbound movements to the target
 * warehouse) and supports partial receiving: each line tracks its ordered
 * quantity and received-so-far quantity.
 */
class PurchaseOrderService
{
    public function __construct(
        protected StockMovementService $movements,
        protected AuditService $audit
    ) {}

    /**
     * Create a PO in draft state with line items.
     *
     * @param array $data [
     *     'supplier_id'        => int (required)
     *     'warehouse_id'       => int (required)
     *     'order_date'         => date|null
     *     'expected_delivery'  => date|null
     *     'tax_rate'           => float|null  (percent, default 0)
     *     'notes'              => string|null
     *     'items'              => array<['inventory_item_id'=>int,'quantity'=>int>0,'unit_cost'=>float>=0]>
     * ]
     */
    public function createDraft(array $data, ?int $userId = null): PurchaseOrder
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('At least one purchase order item is required.');
        }

        return DB::transaction(function () use ($data, $userId) {
            $tenant = Tenant::current();
            if (!$tenant) {
                throw new RuntimeException('No current tenant resolved.');
            }

            $subtotal = 0.0;
            foreach ($data['items'] as $line) {
                $qty     = (int) $line['quantity'];
                $cost    = (float) $line['unit_cost'];
                if ($qty <= 0 || $cost < 0) {
                    throw new RuntimeException('Each PO line needs quantity > 0 and unit_cost >= 0.');
                }
                $subtotal += $qty * $cost;
            }

            $taxRate = (float) ($data['tax_rate'] ?? 0);
            $taxAmt  = round($subtotal * ($taxRate / 100), 2);
            $total   = round($subtotal + $taxAmt, 2);

            $po = PurchaseOrder::create([
                'tenant_id'          => $tenant->id,
                'supplier_id'        => $data['supplier_id'],
                'warehouse_id'       => $data['warehouse_id'],
                'po_number'          => $this->generatePoNumber(),
                'status'             => 'draft',
                'order_date'         => $data['order_date'] ?? now(),
                'expected_delivery'  => $data['expected_delivery'] ?? null,
                'subtotal'           => $subtotal,
                'tax_amount'         => $taxAmt,
                'total_amount'       => $total,
                'notes'              => $data['notes'] ?? null,
                'metadata'           => array_merge($data['metadata'] ?? [], ['tax_rate' => $taxRate]),
                'created_by'         => $userId,
            ]);

            foreach ($data['items'] as $line) {
                $qty     = (int) $line['quantity'];
                $cost    = (float) $line['unit_cost'];
                PurchaseOrderItem::create([
                    'tenant_id'          => $po->tenant_id,
                    'purchase_order_id'  => $po->id,
                    'inventory_item_id'  => $line['inventory_item_id'],
                    'quantity'           => $qty,
                    'unit_cost'          => $cost,
                    'total_cost'         => round($qty * $cost, 2),
                    'quantity_received'  => 0,
                    'notes'              => $line['notes'] ?? null,
                    'metadata'           => $line['metadata'] ?? null,
                ]);
            }

            $this->audit->log(
                action: 'inventory.po.created',
                model: 'PurchaseOrder',
                modelId: $po->id,
                newValues: $po->toArray(),
            );

            return $po->fresh(['items', 'supplier', 'warehouse']);
        });
    }

    /**
     * Submit a draft PO to "submitted" (awaiting approval).
     */
    public function submit(PurchaseOrder $po, ?int $userId = null): PurchaseOrder
    {
        $this->assertState($po, ['draft']);
        $po->update(['status' => 'submitted']);
        $this->audit->log('inventory.po.submitted', 'PurchaseOrder', $po->id, newValues: ['status' => 'submitted']);

        return $po->fresh(['items', 'supplier', 'warehouse']);
    }

    /**
     * Approve a submitted PO.
     */
    public function approve(PurchaseOrder $po, ?int $userId = null): PurchaseOrder
    {
        $this->assertState($po, ['submitted']);
        $po->update(['status' => 'approved', 'approved_by' => $userId]);
        $this->audit->log('inventory.po.approved', 'PurchaseOrder', $po->id, newValues: ['status' => 'approved']);

        return $po->fresh(['items', 'supplier', 'warehouse']);
    }

    /**
     * Receive a fully- or partially-shipped delivery. For each supplied line
     * quantity, record an inbound stock movement to the PO's warehouse and
     * update the received-so-far counter. When all lines are fully received
     * the PO advances to 'received', otherwise 'partially_received'.
     *
     * @param array $data ['items' => array<['purchase_order_item_id'=>int,'quantity'=>int>0]>]
     */
    public function receive(PurchaseOrder $po, array $data, ?int $userId = null): PurchaseOrder
    {
        $this->assertState($po, ['approved', 'partially_received']);

        return DB::transaction(function () use ($po, $data, $userId) {
            $items = $po->items()->get()->keyBy('id');
            $allComplete = true;

            foreach ($data['items'] as $line) {
                $poItem = $items->get($line['purchase_order_item_id']);
                if (!$poItem) {
                    throw new RuntimeException("PO item not found: {$line['purchase_order_item_id']}");
                }

                $qty = (int) $line['quantity'];
                if ($qty <= 0) {
                    throw new RuntimeException('Received quantity must be positive.');
                }

                $remaining = $poItem->quantity - $poItem->quantity_received;
                if ($qty > $remaining) {
                    throw new RuntimeException(
                        "Cannot receive {$qty} for item #{$poItem->id}; only {$remaining} outstanding."
                    );
                }

                $this->movements->record([
                    'warehouse_id'      => $po->warehouse_id,
                    'inventory_item_id' => $poItem->inventory_item_id,
                    'quantity'          => $qty,
                    'movement_type'     => 'in',
                    'unit_cost'         => (float) $poItem->unit_cost,
                    'reference_type'    => 'purchase_order',
                    'reference_id'      => $po->id,
                    'notes'             => "PO {$po->po_number} line {$poItem->id} received",
                    'metadata'          => ['po_item_id' => $poItem->id],
                ], $userId);

                $poItem->increment('quantity_received', $qty);

                if ($poItem->quantity_received < $poItem->quantity) {
                    $allComplete = false;
                }
            }

            $po->update([
                'status'         => $allComplete ? 'received' : 'partially_received',
                'received_date'  => now(),
            ]);

            $this->audit->log(
                action: 'inventory.po.received',
                model: 'PurchaseOrder',
                modelId: $po->id,
                newValues: ['status' => $po->status],
            );

            return $po->fresh(['items', 'supplier', 'warehouse']);
        });
    }

    /**
     * Mark a fully-received PO as completed.
     */
    public function complete(PurchaseOrder $po, ?int $userId = null): PurchaseOrder
    {
        $this->assertState($po, ['received']);

        foreach ($po->items as $line) {
            if ($line->quantity_received < $line->quantity) {
                throw new RuntimeException('Cannot complete a PO with unreceived lines.');
            }
        }

        $po->update(['status' => 'completed']);
        $this->audit->log('inventory.po.completed', 'PurchaseOrder', $po->id, newValues: ['status' => 'completed']);

        return $po->fresh(['items', 'supplier', 'warehouse']);
    }

    /**
     * Cancel a draft or submitted PO (nothing received yet).
     */
    public function cancel(PurchaseOrder $po, ?int $userId = null, ?string $reason = null): PurchaseOrder
    {
        $this->assertState($po, ['draft', 'submitted']);

        $po->update([
            'status'   => 'cancelled',
            'metadata' => array_merge($po->metadata ?? [], ['cancel_reason' => $reason]),
        ]);

        $this->audit->log('inventory.po.cancelled', 'PurchaseOrder', $po->id, newValues: ['status' => 'cancelled', 'reason' => $reason]);

        return $po->fresh(['items', 'supplier', 'warehouse']);
    }

    /**
     * List purchase orders with filters.
     */
    public function list(array $filters = [])
    {
        $query = PurchaseOrder::with('items', 'supplier', 'warehouse', 'creator');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (!empty($filters['search'])) {
            $query->where('po_number', 'like', "%{$filters['search']}%");
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    private function assertState(PurchaseOrder $po, array $allowed): void
    {
        if (!in_array($po->status, $allowed, true)) {
            throw new RuntimeException(
                "Purchase order is in '{$po->status}' state and cannot perform this action."
            );
        }
    }

    private function generatePoNumber(): string
    {
        return 'PO-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
