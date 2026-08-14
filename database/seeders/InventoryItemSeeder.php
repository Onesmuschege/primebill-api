<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the ISP inventory catalog per tenant (router appliances, ONU/ONT,
 * fiber cable, SFP modules, patch cords, power supplies and accessories).
 * Idempotent on tenant + name.
 */
class InventoryItemSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $items = [
                ['name' => 'MikroTik hAP ac2',              'category' => 'Routers',     'quantity' => 10, 'unit_cost' => 4500.00, 'status' => 'in_stock', 'low_stock_alert' => 3],
                ['name' => 'MikroTik RB2011UiAS',           'category' => 'Routers',     'quantity' => 8,  'unit_cost' => 7200.00, 'status' => 'in_stock', 'low_stock_alert' => 2],
                                ['name' => 'MikroTik CCR1036-8G',           'category' => 'Routers',     'quantity' => 2,  'unit_cost' => 145000.00, 'status' => 'in_stock', 'low_stock_alert' => 1],
                ['name' => 'FiberHome AN5506 ONT',          'category' => 'ONU/ONT',     'quantity' => 40, 'unit_cost' => 1850.00, 'status' => 'in_stock', 'low_stock_alert' => 10],
                ['name' => 'Huawei EchoLife HG8310M ONT',   'category' => 'ONU/ONT',     'quantity' => 35, 'unit_cost' => 2100.00, 'status' => 'in_stock', 'low_stock_alert' => 8],
                ['name' => 'Fiber Cable (m)',               'category' => 'Fiber Cable', 'quantity' => 5000, 'unit_cost' => 28.00, 'status' => 'in_stock', 'low_stock_alert' => 500],
                ['name' => 'SC/APC Patch Cord 3m',          'category' => 'Patch Cords', 'quantity' => 120, 'unit_cost' => 320.00, 'status' => 'in_stock', 'low_stock_alert' => 30],
                ['name' => 'SFP 1.25G SM Module',           'category' => 'SFP Modules', 'quantity' => 25, 'unit_cost' => 2400.00, 'status' => 'in_stock', 'low_stock_alert' => 5],
                                ['name' => 'SFP+ 10G LR Module',            'category' => 'SFP Modules', 'quantity' => 10, 'unit_cost' => 6200.00, 'status' => 'in_stock', 'low_stock_alert' => 4],
                ['name' => 'Power Supply 48V',              'category' => 'Power Supply', 'quantity' => 12, 'unit_cost' => 3200.00, 'status' => 'in_stock', 'low_stock_alert' => 3],
                ['name' => 'UPS 600VA',                     'category' => 'Power Supply', 'quantity' => 6,  'unit_cost' => 11500.00, 'status' => 'in_stock', 'low_stock_alert' => 2],
                ['name' => 'Cat6 Cable (m)',                'category' => 'Accessories', 'quantity' => 800, 'unit_cost' => 13.00, 'status' => 'in_stock', 'low_stock_alert' => 100],
                ['name' => 'RJ45 Connectors',               'category' => 'Accessories', 'quantity' => 200, 'unit_cost' => 12.00, 'status' => 'in_stock', 'low_stock_alert' => 40],
                ['name' => 'Fiber Splice Tray',             'category' => 'Accessories', 'quantity' => 60, 'unit_cost' => 850.00, 'status' => 'in_stock', 'low_stock_alert' => 15],
                ['name' => 'Network Patch Panel 24-Port',   'category' => 'Accessories', 'quantity' => 9,  'unit_cost' => 3600.00, 'status' => 'in_stock', 'low_stock_alert' => 2],
                ['name' => 'TP-Link CPE210',                'category' => 'Antennas',    'quantity' => 15, 'unit_cost' => 3500.00, 'status' => 'in_stock', 'low_stock_alert' => 5],
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
