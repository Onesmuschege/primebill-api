<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\InventoryAssignment;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Assigns inventory items to clients, technicians (users) and work orders.
 * Morphable assigned_to (client | user | work_order). Idempotent per tenant.
 */
class InventoryAssignmentSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $items = InventoryItem::where('tenant_id', $tenant->id)->get();
            if ($items->isEmpty()) {
                $this->command->warn("InventoryAssignmentSeeder [{$tenant->slug}]: No inventory items found. Skipping.");
                return;
            }

            if (InventoryAssignment::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Inventory assignments already present — skipped.");
                return;
            }

            $clients = Client::where('tenant_id', $tenant->id)->where('status', 'active')->get();
            $users = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'staff'))
                ->get();
            $workOrders = WorkOrder::where('tenant_id', $tenant->id)->get();

            $created = 0;
            foreach ($items as $index => $item) {
                if ($index % 2 === 0 && $clients->isNotEmpty()) {
                    $target = $clients[$index % $clients->count()];
                    $type = 'client';
                    $id = $target->id;
                } elseif ($index % 4 === 1 && $users->isNotEmpty()) {
                    $target = $users[$index % $users->count()];
                    $type = 'user';
                    $id = $target->id;
                } elseif ($workOrders->isNotEmpty()) {
                    $target = $workOrders[$index % $workOrders->count()];
                    $type = 'work_order';
                    $id = $target->id;
                } else {
                    continue;
                }

                $status = $index % 5 === 3 ? 'returned' : 'active';
                $assignedDate = Carbon::now()->subDays(($index * 4) % 90);
                InventoryAssignment::create([
                    'tenant_id' => $tenant->id,
                    'inventory_item_id' => $item->id,
                    'assigned_to_type' => $type,
                    'assigned_to_id' => $id,
                    'status' => $status,
                    'assigned_date' => $assignedDate,
                    'returned_date' => $status === 'returned' ? $assignedDate->copy()->addDays(18) : null,
                    'notes' => $type === 'client' ? 'Installed at customer premises' : ($type === 'work_order' ? 'Allocated for field job' : 'Checked out to technician'),
                    'metadata' => ['seed_batch' => 'inventory-assignment-' . $item->id],
                    'assigned_by' => $admin?->id,
                    'returned_by' => $status === 'returned' ? $admin?->id : null,
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} inventory assignments seeded.");
        });

        $this->command->info('InventoryAssignmentSeeder: complete.');
    }
}
