<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Seeds realistic ISP equipment inventory.
 * Categories: Routers, Cables, Antennas, Power, Tools.
 * Some items assigned to clients (installed at premises).
 */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $clients       = Client::where('status', 'active')->pluck('id')->toArray();
        $assignedCount = 0;

        $items = [
            // ── Routers ───────────────────────────────────────────────────────
            ['name' => 'MikroTik hAP ac²',           'category' => 'Routers',   'quantity' => 8,  'unit_cost' => 4500.00,  'serial_number' => 'MTK-HAP-001', 'status' => 'in_stock',  'low_stock_alert' => 3, 'client' => null],
            ['name' => 'MikroTik RB750Gr3',           'category' => 'Routers',   'quantity' => 3,  'unit_cost' => 7800.00,  'serial_number' => 'MTK-RB-001',  'status' => 'in_stock',  'low_stock_alert' => 2, 'client' => null],
            ['name' => 'MikroTik hAP ac² (Assigned)', 'category' => 'Routers',   'quantity' => 1,  'unit_cost' => 4500.00,  'serial_number' => 'MTK-HAP-021', 'status' => 'assigned',  'low_stock_alert' => 1, 'client' => 0],
            ['name' => 'TP-Link TL-WR840N',           'category' => 'Routers',   'quantity' => 2,  'unit_cost' => 2200.00,  'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 2, 'client' => null],
            ['name' => 'Faulty MikroTik RB951',       'category' => 'Routers',   'quantity' => 1,  'unit_cost' => 3200.00,  'serial_number' => 'MTK-RB951-F', 'status' => 'faulty',    'low_stock_alert' => 1, 'client' => null],

            // ── Outdoor CPE / Antennas ────────────────────────────────────────
            ['name' => 'TP-Link CPE510 Outdoor',      'category' => 'Antennas',  'quantity' => 6,  'unit_cost' => 4800.00,  'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 2, 'client' => null],
            ['name' => 'Ubiquiti NanoStation M5',     'category' => 'Antennas',  'quantity' => 2,  'unit_cost' => 9500.00,  'serial_number' => 'UBI-NS-001',  'status' => 'in_stock',  'low_stock_alert' => 2, 'client' => null],
            ['name' => 'Ubiquiti NanoStation (Assigned)', 'category' => 'Antennas', 'quantity' => 1, 'unit_cost' => 9500.00, 'serial_number' => 'UBI-NS-005', 'status' => 'assigned',  'low_stock_alert' => 1, 'client' => 1],

            // ── Cables ────────────────────────────────────────────────────────
            ['name' => 'CAT6 Cable (metres)',          'category' => 'Cables',    'quantity' => 350, 'unit_cost' => 13.00,   'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 100, 'client' => null],
            ['name' => 'Fibre Optic Patch Cord SC-SC', 'category' => 'Cables',   'quantity' => 12, 'unit_cost' => 850.00,   'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 5, 'client' => null],
            ['name' => 'RJ45 Connectors (box of 100)', 'category' => 'Cables',   'quantity' => 3,  'unit_cost' => 1200.00,  'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 1, 'client' => null],

            // ── Power equipment ───────────────────────────────────────────────
            ['name' => 'APC UPS 1000VA',               'category' => 'Power',    'quantity' => 2,  'unit_cost' => 18500.00, 'serial_number' => 'APC-1000-01', 'status' => 'in_stock',  'low_stock_alert' => 1, 'client' => null],
            ['name' => 'Surge Protector Power Strip',  'category' => 'Power',    'quantity' => 5,  'unit_cost' => 1500.00,  'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 2, 'client' => null],
            ['name' => 'Solar Charge Controller 30A',  'category' => 'Power',    'quantity' => 1,  'unit_cost' => 3500.00,  'serial_number' => 'SCC-30A-001', 'status' => 'in_stock',  'low_stock_alert' => 1, 'client' => null],

            // ── Tools ─────────────────────────────────────────────────────────
            ['name' => 'RJ45 Crimping Tool',           'category' => 'Tools',    'quantity' => 3,  'unit_cost' => 850.00,   'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 1, 'client' => null],
            ['name' => 'Network Cable Tester',         'category' => 'Tools',    'quantity' => 2,  'unit_cost' => 1200.00,  'serial_number' => null,          'status' => 'in_stock',  'low_stock_alert' => 1, 'client' => null],
            ['name' => 'Lost Ladder (field team)',     'category' => 'Tools',    'quantity' => 1,  'unit_cost' => 4500.00,  'serial_number' => null,          'status' => 'lost',      'low_stock_alert' => 1, 'client' => null],
        ];

        foreach ($items as $item) {
            $clientId = null;
            if ($item['client'] !== null && ! empty($clients)) {
                $clientId = $clients[$item['client'] % count($clients)];
                $assignedCount++;
            }

            DB::table('inventory_items')->insert([
                'name'                   => $item['name'],
                'category'               => $item['category'],
                'quantity'               => $item['quantity'],
                'unit_cost'              => $item['unit_cost'],
                'serial_number'          => $item['serial_number'],
                'assigned_to_client_id'  => $clientId,
                'status'                 => $item['status'],
                'low_stock_alert'        => $item['low_stock_alert'],
                'created_at'             => Carbon::now()->subDays(rand(1, 90)),
                'updated_at'             => Carbon::now()->subDays(rand(0, 30)),
            ]);
        }

        $this->command->info('InventorySeeder: ' . count($items) . ' inventory items seeded (' . $assignedCount . ' assigned to clients).');
    }
}
