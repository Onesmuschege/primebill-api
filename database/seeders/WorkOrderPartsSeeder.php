<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds work-order parts referencing real inventory items. Idempotent via
 * a per-tenant guard.
 */
class WorkOrderPartsSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $workOrders = WorkOrder::where('tenant_id', $tenant->id)->get();
            $items = InventoryItem::where('tenant_id', $tenant->id)->get();
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            if ($workOrders->isEmpty()) {
                $this->command->warn("WorkOrderPartsSeeder [{$tenant->slug}]: No work order found. Skipping.");
                return;
            }

            if (WorkOrderPart::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Work order parts already present — skipped.");
                return;
            }

            $parts = [
                ['name' => 'MikroTik hAP ac2', 'part_number' => 'RTR-HAP-AC2'],
                ['name' => 'FiberHome AN5506 ONT', 'part_number' => 'ONT-FH-AN5506'],
                ['name' => 'SC/APC Patch Cord 3m', 'part_number' => 'PCH-SCAPC-3M'],
                ['name' => 'SFP 1.25G SM Module', 'part_number' => 'SFP-125G-SM'],
            ];

            $created = 0;
            foreach ($workOrders->take(6) as $index => $order) {
                $part = $parts[$index % count($parts)];
                $item = $items->firstWhere('name', $part['name']) ?? $items->first();

                $unitCost = (float) ($item?->unit_cost ?? 1500);
                $quantity = 1 + ($index % 2);
                WorkOrderPart::create([
                    'tenant_id' => $tenant->id,
                    'work_order_id' => $order->id,
                    'inventory_item_id' => $item?->id,
                    'part_name' => $part['name'],
                    'part_number' => $part['part_number'],
                    'serial_number' => $item?->serial_number,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => round($unitCost * $quantity, 2),
                    'status' => ['planned', 'installed', 'ordered'][$index % 3],
                    'notes' => 'Seeded work-order part',
                    'metadata' => ['seed' => 'wo-part-' . $order->id . '-' . $index],
                    'created_by' => $admin?->id,
                    'updated_by' => $admin?->id,
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} work order parts seeded.");
        });

        $this->command->info('WorkOrderPartsSeeder: complete.');
    }
}
