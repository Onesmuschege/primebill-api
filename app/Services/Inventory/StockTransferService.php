<?php

namespace App\Services\Inventory;

use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Tenant;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * StockTransferService
 *
 * Warehouse-to-warehouse stock transfer lifecycle engine.
 *
 * State machine:
 *   draft → approved → dispatched → received
 *   draft → cancelled
 *   approved → cancelled
 *   dispatched → reversed (when received in error)
 *
 * On receiving:
 *   - source warehouse stock decreases (outbound movement)
 *   - destination warehouse stock increases (inbound movement)
 *   - transfer status advances to received
 *   - movement records + history are created transactionally
 *
 * Guards (all inside one DB transaction):
 *   - double receiving / receiving a cancelled transfer is prevented
 *   - source stock can never drop below zero
 *   - only the owning tenant's warehouses are touched (via BelongsToTenant)
 */
class StockTransferService
{
    public function __construct(
        protected StockMovementService $movements,
        protected AuditService $audit
    ) {}

    /**
     * Create a transfer in draft state with its line items.
     *
     * @param array $data [
     *     'source_warehouse_id'      => int (required)
     *     'destination_warehouse_id' => int (required, different from source)
     *     'expected_date'            => date|null
     *     'notes'                    => string|null
     *     'items'                    => array<['inventory_item_id'=>int,'quantity'=>int>0]> (required)
     * ]
     */
    public function createDraft(array $data, ?int $userId = null): StockTransfer
    {
        if ((int) $data['source_warehouse_id'] === (int) $data['destination_warehouse_id']) {
            throw new RuntimeException('Source and destination warehouse must be different.');
        }

        if (empty($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('At least one transfer item is required.');
        }

        return DB::transaction(function () use ($data, $userId) {
            $tenant = Tenant::current();
            if (!$tenant) {
                throw new RuntimeException('No current tenant resolved.');
            }

            $transfer = StockTransfer::create([
                'tenant_id'               => $tenant->id,
                'source_warehouse_id'     => $data['source_warehouse_id'],
                'destination_warehouse_id'=> $data['destination_warehouse_id'],
                'reference_number'        => $this->generateReference(),
                'status'                  => 'draft',
                'expected_date'           => $data['expected_date'] ?? null,
                'notes'                   => $data['notes'] ?? null,
                'metadata'                => $data['metadata'] ?? null,
                'created_by'              => $userId,
            ]);

            foreach ($data['items'] as $line) {
                $qty = (int) $line['quantity'];
                if ($qty <= 0) {
                    throw new RuntimeException('Transfer item quantity must be positive.');
                }

                StockTransferItem::create([
                    'tenant_id'          => $transfer->tenant_id,
                    'stock_transfer_id'  => $transfer->id,
                    'inventory_item_id'  => $line['inventory_item_id'],
                    'quantity'           => $qty,
                    'notes'              => $line['notes'] ?? null,
                    'metadata'           => $line['metadata'] ?? null,
                ]);
            }

            $this->audit->log(
                action: 'inventory.transfer.created',
                model: 'StockTransfer',
                modelId: $transfer->id,
                newValues: $transfer->toArray(),
            );

            return $transfer->fresh(['items', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * Move a draft transfer to approved. Validates sufficient source stock
     * for every line before approving so the dispatch/receive cannot fail.
     */
    public function approve(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        $this->assertState($transfer, ['draft']);

        return DB::transaction(function () use ($transfer, $userId) {
            foreach ($transfer->items as $line) {
                $item = $line->inventoryItem;
                $source = $transfer->sourceWarehouse;
                $balance = $this->movements->warehouseBalance($item, $source);

                if ($balance < $line->quantity) {
                    throw new RuntimeException(
                        "Insufficient stock to approve transfer. Item \"{$item->name}\" "
                        . "has {$balance} in \"{$source->name}\", needs {$line->quantity}."
                    );
                }
            }

            $transfer->update([
                'status'      => 'approved',
                'approved_by' => $userId,
            ]);

            $this->audit->log(
                action: 'inventory.transfer.approved',
                model: 'StockTransfer',
                modelId: $transfer->id,
                newValues: ['status' => 'approved'],
            );

            return $transfer->fresh(['items', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * Move an approved transfer to dispatched. Stock is reserved from the
     * source warehouse at dispatch time (outbound movement recorded).
     */
    public function dispatch(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        $this->assertState($transfer, ['approved']);

        return DB::transaction(function () use ($transfer, $userId) {
            foreach ($transfer->items as $line) {
                $this->movements->record([
                    'warehouse_id'      => $transfer->source_warehouse_id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'quantity'          => $line->quantity,
                    'movement_type'     => 'out',
                    'reference_type'    => 'stock_transfer',
                    'reference_id'      => $transfer->id,
                    'notes'             => "Dispatched to warehouse #{$transfer->destination_warehouse_id}",
                    'metadata'          => ['transfer_id' => $transfer->id, 'direction' => 'out'],
                ], $userId);
            }

            $transfer->update(['status' => 'dispatched']);

            $this->audit->log(
                action: 'inventory.transfer.dispatched',
                model: 'StockTransfer',
                modelId: $transfer->id,
                newValues: ['status' => 'dispatched'],
            );

            return $transfer->fresh(['items', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * Receive a dispatched transfer into the destination warehouse. Source
     * stock was already reduced at dispatch; this adds the corresponding
     * inbound movement to the destination.
     */
    public function receive(StockTransfer $transfer, ?int $userId = null): StockTransfer
    {
        $this->assertState($transfer, ['dispatched']);

        return DB::transaction(function () use ($transfer, $userId) {
            foreach ($transfer->items as $line) {
                $this->movements->record([
                    'warehouse_id'      => $transfer->destination_warehouse_id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'quantity'          => $line->quantity,
                    'movement_type'     => 'in',
                    'reference_type'    => 'stock_transfer',
                    'reference_id'      => $transfer->id,
                    'notes'             => "Received from warehouse #{$transfer->source_warehouse_id}",
                    'metadata'          => ['transfer_id' => $transfer->id, 'direction' => 'in'],
                ], $userId);
            }

            $transfer->update([
                'status'         => 'received',
                'completed_date' => now(),
            ]);

            $this->audit->log(
                action: 'inventory.transfer.received',
                model: 'StockTransfer',
                modelId: $transfer->id,
                newValues: ['status' => 'received'],
            );

            return $transfer->fresh(['items', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * Cancel a draft or approved transfer (before dispatch). No stock has
     * moved yet, so nothing to reverse.
     */
    public function cancel(StockTransfer $transfer, ?int $userId = null, ?string $reason = null): StockTransfer
    {
        $this->assertState($transfer, ['draft', 'approved']);

        $transfer->update([
            'status'   => 'cancelled',
            'metadata' => array_merge($transfer->metadata ?? [], ['cancel_reason' => $reason]),
        ]);

        $this->audit->log(
            action: 'inventory.transfer.cancelled',
            model: 'StockTransfer',
            modelId: $transfer->id,
            newValues: ['status' => 'cancelled', 'reason' => $reason],
        );

        return $transfer->fresh(['items', 'sourceWarehouse', 'destinationWarehouse']);
    }

    /**
     * Reverse a dispatched transfer that was received in error. The inbound
     * destination movement is reversed (issue from destination) and the
     * outbound source movement is reversed (receive back to source).
     */
    public function reverse(StockTransfer $transfer, ?int $userId = null, ?string $reason = null): StockTransfer
    {
        $this->assertState($transfer, ['dispatched', 'received']);

        return DB::transaction(function () use ($transfer, $userId, $reason) {
            foreach ($transfer->items as $line) {
                // Return to source (receive back).
                $this->movements->record([
                    'warehouse_id'      => $transfer->source_warehouse_id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'quantity'          => $line->quantity,
                    'movement_type'     => 'in',
                    'reference_type'    => 'stock_transfer',
                    'reference_id'      => $transfer->id,
                    'notes'             => "Reverse transfer — returned to source. {$reason}",
                    'metadata'          => ['transfer_id' => $transfer->id, 'direction' => 'reverse_source'],
                ], $userId);

                // Take back from destination (issue out).
                $this->movements->record([
                    'warehouse_id'      => $transfer->destination_warehouse_id,
                    'inventory_item_id' => $line->inventory_item_id,
                    'quantity'          => $line->quantity,
                    'movement_type'     => 'out',
                    'reference_type'    => 'stock_transfer',
                    'reference_id'      => $transfer->id,
                    'notes'             => "Reverse transfer — removed from destination. {$reason}",
                    'metadata'          => ['transfer_id' => $transfer->id, 'direction' => 'reverse_destination'],
                ], $userId);
            }

            $transfer->update([
                'status'   => 'reversed',
                'metadata' => array_merge($transfer->metadata ?? [], ['reverse_reason' => $reason]),
            ]);

            $this->audit->log(
                action: 'inventory.transfer.reversed',
                model: 'StockTransfer',
                modelId: $transfer->id,
                newValues: ['status' => 'reversed', 'reason' => $reason],
            );

            return $transfer->fresh(['items', 'sourceWarehouse', 'destinationWarehouse']);
        });
    }

    /**
     * List transfers with filters.
     */
    public function list(array $filters = [])
    {
        $query = StockTransfer::with('items', 'sourceWarehouse', 'destinationWarehouse', 'creator');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['source_warehouse_id'])) {
            $query->where('source_warehouse_id', $filters['source_warehouse_id']);
        }

        if (!empty($filters['destination_warehouse_id'])) {
            $query->where('destination_warehouse_id', $filters['destination_warehouse_id']);
        }

        if (!empty($filters['search'])) {
            $query->where('reference_number', 'like', "%{$filters['search']}%");
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    private function assertState(StockTransfer $transfer, array $allowed): void
    {
        if (!in_array($transfer->status, $allowed, true)) {
            throw new RuntimeException(
                "Transfer is in '{$transfer->status}' state and cannot perform this action."
            );
        }
    }

    private function generateReference(): string
    {
        return 'ST-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }
}
