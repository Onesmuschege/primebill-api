<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class WorkOrderPartsSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $workOrder = WorkOrder::where('tenant_id', $tenant->id)->first();

            if (! $workOrder) {
                $this->command->warn("WorkOrderPartsSeeder [{$tenant->slug}]: No work order found. Skipping.");
                return;
            }

            WorkOrderPart::create([
                'tenant_id' => $tenant->id,
                'work_order_id' => $workOrder->id,
                'name' => 'MikroTik Router',
                'quantity' => 1,
                'unit_cost' => 4500.00,
            ]);

            $this->command->line("  [{$tenant->slug}] Work order parts seeded.");
        });

        $this->command->info('WorkOrderPartsSeeder: complete.');
    }
}
