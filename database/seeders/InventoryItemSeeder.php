<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class InventoryItemSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $items = [
                ['name' => 'MikroTik hAP ac2', 'category' => 'Routers', 'quantity' => 10, 'unit_cost' => 4500.00, 'status' => 'in_stock', 'low_stock_alert' => 3],
                ['name' => 'CAT6 Cable (m)', 'category' => 'Cables', 'quantity' => 500, 'unit_cost' => 13.00, 'status' => 'in_stock', 'low_stock_alert' => 100],
                ['name' => 'RJ45 Connectors', 'category' => 'Cables', 'quantity' => 50, 'unit_cost' => 12.00, 'status' => 'in_stock', 'low_stock_alert' => 10],
                ['name' => 'TP-Link CPE210', 'category' => 'Antennas', 'quantity' => 15, 'unit_cost' => 3500.00, 'status' => 'in_stock', 'low_stock_alert' => 5],
            ];

            foreach ($items as $item) {
                InventoryItem::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $item['name']],
                    array_merge($item, [
                        'tenant_id' => $tenant->id,
                        'created_at' => Carbon::now()->subDays(rand(1, 90)),
                        'updated_at' => now(),
                    ])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($items) . " inventory items seeded.");
        });

        $this->command->info('InventoryItemSeeder: complete.');
    }
}
