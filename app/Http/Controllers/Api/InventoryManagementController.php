<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\HandlesCatalogResources;
use App\Http\Controllers\Controller;
use App\Models\InventoryAssignment;
use App\Models\InventoryItemHistory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\Warranty;

/**
 * InventoryManagementController
 *
 * Domain G — Inventory extensions: warehouses, suppliers, stock movements,
 * stock transfers, purchase orders, warranties and inventory audit trails.
 */
class InventoryManagementController extends Controller
{
    use HandlesCatalogResources;

    protected array $catalogResources = [
        'warehouses' => [
            'model' => Warehouse::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'suppliers' => [
            'model' => Supplier::class,
            'search' => ['name', 'code'],
            'rules' => ['name' => 'required|string|max:255'],
        ],
        'stock-movements' => [
            'model' => StockMovement::class,
            'rules' => [
                'inventory_item_id' => 'required|exists:inventory_items,id',
                'type' => 'required|string|max:50',
            ],
        ],
        'stock-transfers' => [
            'model' => StockTransfer::class,
            'search' => ['reference_number', 'status'],
            'rules' => [
                'source_warehouse_id' => 'required|exists:warehouses,id|different:destination_warehouse_id',
                'destination_warehouse_id' => 'required|exists:warehouses,id',
            ],
        ],
        'stock-transfer-items' => [
            'model' => StockTransferItem::class,
            'rules' => [
                'stock_transfer_id' => 'required|exists:stock_transfers,id',
                'inventory_item_id' => 'required|exists:inventory_items,id',
            ],
        ],
        'purchase-orders' => [
            'model' => PurchaseOrder::class,
            'search' => ['po_number', 'status'],
            'rules' => ['supplier_id' => 'required|exists:suppliers,id'],
        ],
        'purchase-order-items' => [
            'model' => PurchaseOrderItem::class,
            'rules' => [
                'purchase_order_id' => 'required|exists:purchase_orders,id',
                'inventory_item_id' => 'required|exists:inventory_items,id',
            ],
        ],
        'warranties' => [
            'model' => Warranty::class,
            'rules' => ['inventory_item_id' => 'nullable|exists:inventory_items,id'],
        ],
        'inventory-assignments' => [
            'model' => InventoryAssignment::class,
            'search' => ['status'],
            'rules' => ['inventory_item_id' => 'required|exists:inventory_items,id'],
        ],
        'inventory-item-histories' => [
            'model' => InventoryItemHistory::class,
            'search' => ['action'],
        ],
    ];
}
