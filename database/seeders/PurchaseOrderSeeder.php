<?php

namespace Database\Seeders;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PurchaseOrderSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $supplier = Supplier::where('tenant_id', $tenant->id)->first();

            if (! $supplier) {
                $this->command->warn("PurchaseOrderSeeder [{$tenant->slug}]: No supplier found. Skipping.");
                return;
            }

            PurchaseOrder::create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplier->id,
                'order_date' => Carbon::now()->subDays(20),
                'total_amount' => 45000.00,
                'status' => 'received',
            ]);

            $this->command->line("  [{$tenant->slug}] Purchase order seeded.");
        });

        $this->command->info('PurchaseOrderSeeder: complete.');
    }
}
