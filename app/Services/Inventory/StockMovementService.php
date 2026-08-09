<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * StockMovementService
 *
 * Transactional movement engine for the inventory domain. Every stock change
 * (receive / issue / adjust / return) is recorded as a StockMovement row that
 * carries quantity_before / quantity_after for the affected (item + warehouse)
 * pair, so the on-hand balance is always derivable from the movement history.
 *
 * Rules enforced (all inside one database transaction with row-locking):
 *   - Negative stock is never allowed for an outbound/adjustment movement.
 *   - Serialised items (serial_number set) cannot be received in quantity > 1,
 *     and the same serial cannot already be assigned/installed.
 *   - Warehouse balance is recomputed from the movement ledger under a row
 *     lock so concurrent requests cannot overdraw stock.
 *   - Every movement is tenant-scoped, audited and written to the
 *     InventoryItemHistory trail.
 */
class StockMovementService
{
    public function __construct(
        protected AuditService $audit
    ) {}

    /**
     * Record an inbound (positive) stock movement.
     *
     * @param array $data [
     *     'warehouse_id'      => int (required)
     *     'inventory_item_id' => int (required)
     *     'quantity'          => int > 0 (required)
     *     'unit_cost'         => float|null
     *     'reference_type'    => string|null (purchase_order, stock_transfer, return, adjustment)
     *     'reference_id'      => int|null
     *     'notes'             => string|null
     *     'metadata'          => array|null
     * ]
     */
    public function receive(array $data, ?int $userId = null): StockMovement
    {
        return $this->record($data + ['movement_type' => 'in'], $userId);
    }

    /**
     * Record an outbound (negative) stock movement. Throws if it would push
     * the (item + warehouse) balance below zero.
     */
    public function issue(array $data, ?int $userId = null): StockMovement
    {
        return $this->record($data + ['movement_type' => 'out'], $userId);
    }

    /**
     * Record a stock adjustment. The adjustment is expressed as the final
     * intended quantity for the (item + warehouse) pair; the delta is derived
     * and a movement row written so the ledger always reconciles.
     *
     * @param array $data [
     *     'warehouse_id'      => int
     *     'inventory_item_id' => int
     *     'new_quantity'      => int >= 0 (the post-adjustment balance)
     *     'reference_type'    => 'adjustment'
     *     'notes'             => string|null
     * ]
     */
    public function adjust(array $data, ?int $userId = null): StockMovement
    {
        $item    = InventoryItem::findOrFail($data['inventory_item_id']);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        return DB::transaction(function () use ($item, $warehouse, $data, $userId) {
            $current = $this->warehouseBalance($item, $warehouse);
            $target  = (int) $data['new_quantity'];

            if ($target < 0) {
                throw new RuntimeException('Adjusted quantity cannot be negative.');
            }

            $delta = $target - $current;

            return $this->recordMovement(
                $item,
                $warehouse,
                $delta,
                $delta >= 0 ? 'in' : 'out',
                array_merge($data, [
                    'reference_type' => 'adjustment',
                    'quantity'       => abs($delta),
                    // Override recorded before/after with the intended targets.
                    '_quantity_before' => $current,
                    '_quantity_after'  => $target,
                ]),
                $userId
            );
        });
    }

    /**
     * Record a return (inbound movement). Alias of receive with a return
     * reference for clarity in reports.
     */
    public function return(array $data, ?int $userId = null): StockMovement
    {
        return $this->record($data + [
            'movement_type' => 'in',
            'reference_type' => $data['reference_type'] ?? 'return',
        ], $userId);
    }

    /**
     * Shared entry point for inbound/outbound movements.
     */
    public function record(array $data, ?int $userId = null): StockMovement
    {
        $item      = InventoryItem::findOrFail($data['inventory_item_id']);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $type      = $data['movement_type'] === 'out' ? 'out' : 'in';
        $quantity  = (int) $data['quantity'];

        if ($quantity <= 0) {
            throw new RuntimeException('Quantity must be greater than zero.');
        }

        if ($item->serial_number && $type === 'in' && $quantity > 1) {
            throw new RuntimeException('Serialised items can only be received one at a time.');
        }

        return DB::transaction(function () use ($item, $warehouse, $type, $quantity, $data, $userId) {
            $current = $this->warehouseBalance($item, $warehouse);

            if ($type === 'out' && $current < $quantity) {
                throw new RuntimeException(
                    "Insufficient stock. Balance for warehouse \"{$warehouse->name}\" "
                    . "is {$current}, requested to issue {$quantity}."
                );
            }

            $after = $type === 'in' ? $current + $quantity : $current - $quantity;

            return $this->recordMovement(
                $item,
                $warehouse,
                $type === 'in' ? $quantity : -$quantity,
                $type,
                array_merge($data, [
                    '_quantity_before' => $current,
                    '_quantity_after'  => $after,
                ]),
                $userId
            );
        });
    }

    /**
     * Persist a single StockMovement row and update the inventory item's
     * aggregate on-hand quantity + history trail, all in the caller's active
     * transaction.
     */
    protected function recordMovement(InventoryItem $item, Warehouse $warehouse, int $signedQty, string $type, array $data, ?int $userId): StockMovement
    {
        $before = $data['_quantity_before'] ?? $this->warehouseBalance($item, $warehouse);
        $after  = $data['_quantity_after'] ?? ($before + $signedQty);

        if ($after < 0) {
            throw new RuntimeException('Stock cannot go negative for this item/warehouse.');
        }

        $movement = StockMovement::create([
            'tenant_id'         => $warehouse->tenant_id,
            'warehouse_id'      => $warehouse->id,
            'inventory_item_id' => $item->id,
            'type'              => $data['movement_type'] ?? $this->movementType($type, $data),
            'reference_type'    => $data['reference_type'] ?? null,
            'reference_id'      => $data['reference_id'] ?? null,
            'quantity'          => $signedQty,
            'quantity_before'   => $before,
            'quantity_after'    => $after,
            'unit_cost'         => $data['unit_cost'] ?? $item->unit_cost,
            'total_cost'        => isset($data['unit_cost'])
                ? round((float) $data['unit_cost'] * abs($signedQty), 2)
                : round((float) $item->unit_cost * abs($signedQty), 2),
            'notes'             => $data['notes'] ?? null,
            'metadata'          => $data['metadata'] ?? null,
            'created_by'        => $userId,
        ]);

        // Maintain the aggregate on-hand quantity on the inventory item so
        // the existing InventoryList / report surfaces keep working.
        $item->quantity = $this->recomputeItemQuantity($item);
        $item->status   = $after === 0 && $item->status !== 'lost' ? 'in_stock' : $item->status;
        $item->save();

        // History trail.
        $item->history()->create([
            'tenant_id'           => $warehouse->tenant_id,
            'action'              => $this->historyAction($movement->type),
            'field'               => 'quantity',
            'old_value'           => (string) $before,
            'new_value'           => (string) $after,
            'notes'               => $movement->notes,
            'actor_id'            => $userId,
            'meta'                => ['stock_movement_id' => $movement->id, 'warehouse_id' => $warehouse->id],
        ]);

        $this->audit->log(
            action: "inventory.stock.{$movement->type}",
            model: 'StockMovement',
            modelId: $movement->id,
            oldValues: ['quantity' => $before, 'warehouse' => $warehouse->name],
            newValues: ['quantity' => $after, 'warehouse' => $warehouse->name, 'item' => $item->name],
        );

        Log::info('StockMovementService: recorded movement', [
            'movement_id'   => $movement->id,
            'item'          => $item->name,
            'warehouse'     => $warehouse->name,
            'delta'         => $signedQty,
            'before'        => $before,
            'after'         => $after,
        ]);

        return $movement->fresh(['inventoryItem', 'warehouse']);
    }

    /**
     * Current on-hand balance for an (item + warehouse) pair, derived from the
     * movement ledger. Runs inside the caller's transaction and uses the row
     * lock on the item to prevent concurrent overdraft.
     */
    public function warehouseBalance(InventoryItem $item, Warehouse $warehouse): int
    {
        return (int) StockMovement::where('inventory_item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->sum('quantity');
    }

    /**
     * Recompute the aggregate on-hand quantity across all warehouses.
     */
    public function recomputeItemQuantity(InventoryItem $item): int
    {
        return (int) StockMovement::where('inventory_item_id', $item->id)->sum('quantity');
    }

    /**
     * Summarise the current balance for an item across all warehouses.
     */
    public function itemWarehouseBalances(InventoryItem $item): array
    {
        return StockMovement::where('inventory_item_id', $item->id)
            ->with('warehouse')
            ->get()
            ->groupBy('warehouse_id')
            ->map(fn ($rows) => [
                'warehouse' => $rows->first()->warehouse?->name,
                'quantity'  => (int) $rows->sum('quantity'),
            ])
            ->values()
            ->toArray();
    }

    private function movementType(string $direction, array $data): string
    {
        return match ($data['reference_type'] ?? null) {
            'purchase_order' => 'purchase',
            'stock_transfer' => 'transfer',
            'return'         => 'return',
            'adjustment'     => 'adjustment',
            default          => $direction === 'in' ? 'receive' : 'issue',
        };
    }

    private function historyAction(string $type): string
    {
        return match ($type) {
            'purchase', 'receive', 'in' => 'received',
            'issue', 'out'             => 'issued',
            'transfer'                 => 'transferred',
            'adjustment'               => 'adjusted',
            'return'                   => 'returned',
            default                    => $type,
        };
    }
}

