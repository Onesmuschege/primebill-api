<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Creates realistic purchase orders against existing suppliers and
 * warehouses, each with itemised lines referencing inventory items.
 * Idempotent on the tenant + po_number pair.
 */
class PurchaseOrderSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $suppliers = Supplier::where('tenant_id', $tenant->id)->get();
            $warehouse = Warehouse::where('tenant_id', $tenant->id)->first();
            $items = InventoryItem::where('tenant_id', $tenant->id)->get();
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            if ($suppliers->isEmpty() || ! $warehouse || $items->isEmpty()) {
                $this->command->warn("PurchaseOrderSeeder [{$tenant->slug}]: Missing suppliers/warehouse/items. Skipping.");
                return;
            }

            $tpl = [
                ['status' => 'received',  'days' => 35, 'received' => true],
                ['status' => 'ordered',   'days' => 10, 'received' => false],
                ['status' => 'draft',     'days' => 3,  'received' => false],
            ];

            $created = 0;
            foreach ($tpl as $i => $po) {
                $poNumber = 'PO-' . $tenant->id . '-' . date('Y') . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);

                $supplier = $suppliers[$i % $suppliers->count()];
                $lineItems = $items->take(3 - $i);

                $subtotal = 0;
                $lineData = [];
                foreach ($lineItems as $li) {
                    $qty = 5 + (($i + $li->id) % 20);
                    $cost = (float) $li->unit_cost;
                    $lineTotal = round($cost * $qty, 2);
                    $subtotal += $lineTotal;
                    $lineData[] = [
                        'inventory_item_id' => $li->id,
                        'quantity' => $qty,
                        'unit_cost' => $cost,
                        'total_cost' => $lineTotal,
                        'quantity_received' => $po['received'] ? $qty : 0,
                        'notes' => 'Restock line',
                    ];
                }

                $tax = round($subtotal * 0.16, 2);
                $total = round($subtotal + $tax, 2);
                $orderDate = Carbon::now()->subDays($po['days']);

                $purchaseOrder = PurchaseOrder::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'po_number' => $poNumber],
                    [
                        'supplier_id' => $supplier->id,
                        'warehouse_id' => $warehouse->id,
                        'po_number' => $poNumber,
                        'status' => $po['status'],
                        'order_date' => $orderDate,
                        'expected_delivery' => $orderDate->copy()->addDays(21),
                        'received_date' => $po['received'] ? $orderDate->copy()->addDays(14) : null,
                        'subtotal' => $subtotal,
                        'tax_amount' => $tax,
                        'total_amount' => $total,
                        'notes' => 'Seed purchase order for ' . $supplier->name,
                        'created_by' => $admin?->id,
                        'approved_by' => $admin?->id,
                    ]
                );

                foreach ($lineData as $line) {
                    PurchaseOrderItem::updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'purchase_order_id' => $purchaseOrder->id,
                            'inventory_item_id' => $line['inventory_item_id'],
                        ],
                        array_merge($line, ['tenant_id' => $tenant->id, 'purchase_order_id' => $purchaseOrder->id])
                    );
                }

                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} purchase orders (with line items) seeded.");
        });

        $this->command->info('PurchaseOrderSeeder: complete.');
    }
}
