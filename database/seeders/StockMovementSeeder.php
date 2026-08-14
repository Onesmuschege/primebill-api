<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Creates realistic stock movements (purchase-in, issue-out, adjustment,
 * consumption) for each inventory item against the tenant's first warehouse.
 * Idempotent: skips when the tenant already has movements.
 */
class StockMovementSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            if (StockMovement::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Stock movements already present — skipped.");
                return;
            }

            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $warehouse = Warehouse::where('tenant_id', $tenant->id)->first();
            if (! $warehouse) {
                $this->command->warn("StockMovementSeeder [{$tenant->slug}]: No warehouse found. Skipping.");
                return;
            }

            $items = InventoryItem::where('tenant_id', $tenant->id)->get();
            $created = 0;

            foreach ($items as $index => $item) {
                $id = $item->id;
                $unitCost = (float) $item->unit_cost;
                $qty = max(1, (int) $item->quantity);

                // 1. Initial stock-in via purchase.
                StockMovement::create([
                    'tenant_id' => $tenant->id,
                    'warehouse_id' => $warehouse->id,
                    'inventory_item_id' => $item->id,
                    'type' => 'purchase',
                    'reference_type' => 'purchase_order',
                    'reference_id' => null,
                    'quantity' => $qty,
                    'quantity_before' => 0,
                    'quantity_after' => $qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => round($unitCost * $qty, 2),
                    'notes' => 'Opening stock (purchase-in)',
                    'metadata' => ['batch' => 'seed-opening-' . $id],
                    'created_by' => $admin?->id,
                    'created_at' => Carbon::now()->subDays(120 - ($index * 3)),
                    'updated_at' => Carbon::now()->subDays(120 - ($index * 3)),
                ]);
                $created++;

                // 2. Consumption/issue-out for a portion.
                $out = max(1, intdiv($qty, 4));
                StockMovement::create([
                    'tenant_id' => $tenant->id,
                    'warehouse_id' => $warehouse->id,
                    'inventory_item_id' => $item->id,
                    'type' => 'consumption',
                    'reference_type' => 'work_order',
                    'reference_id' => null,
                    'quantity' => -$out,
                    'quantity_before' => $qty,
                    'quantity_after' => max(0, $qty - $out),
                    'unit_cost' => $unitCost,
                    'total_cost' => round($unitCost * $out, 2),
                    'notes' => 'Field consumption',
                    'metadata' => ['batch' => 'seed-consumption-' . $id],
                    'created_by' => $admin?->id,
                    'created_at' => Carbon::now()->subDays(60 - ($index * 2)),
                    'updated_at' => Carbon::now()->subDays(60 - ($index * 2)),
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} stock movements seeded.");
        });

        $this->command->info('StockMovementSeeder: complete.');
    }
}
