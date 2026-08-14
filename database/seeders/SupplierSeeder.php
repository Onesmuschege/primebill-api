<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds ISP network-equipment suppliers per tenant. Resolves the root
 * dependency that previously left PurchaseOrderSeeder with no suppliers.
 * Idempotent on tenant + code.
 */
class SupplierSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $suppliers = [
                [
                    'name' => 'MikroTik Kenya Distributor',
                    'code' => 'SUP-001',
                    'description' => 'Authorized MikroTik router and RouterBoard distributor.',
                    'contact_person' => 'John Kamau',
                    'phone' => '+254711222333',
                    'email' => 'sales@mikrotik-ke.example', 'city' => 'Nairobi', 'country' => 'Kenya',
                    'website' => 'https://mikrotik-ke.example',
                ],
                [
                    'name' => 'Huawei Enterprise Kenya',
                    'code' => 'SUP-002',
                    'description' => 'OLT, ONT and GPON optical distribution equipment.',
                    'contact_person' => 'Grace Wanjiru',
                    'phone' => '+254722333444',
                    'email' => 'orders@huawei-ke.example', 'city' => 'Nairobi', 'country' => 'Kenya',
                    'website' => 'https://huawei-ke.example',
                ],
                [
                    'name' => 'Fiberlink Cables Ltd',
                    'code' => 'SUP-003',
                    'description' => 'Fiber cable, patch cords, splice trays and passive network accessories.',
                    'contact_person' => 'Peter Otieno',
                    'phone' => '+254733444555',
                    'email' => 'orders@fiberlink.example', 'city' => 'Mombasa', 'country' => 'Kenya',
                    'website' => 'https://fiberlink.example',
                ],
                [
                    'name' => 'SFG Global Networks',
                    'code' => 'SUP-004',
                    'description' => 'SFP/SFP+ modules, transceivers and enterprise switching gear.',
                    'contact_person' => 'Mary Achieng',
                    'phone' => '+254744555666',
                    'email' => 'sales@sfg-networks.example', 'city' => 'Nairobi', 'country' => 'Kenya',
                    'website' => 'https://sfg-networks.example',
                ],
                [
                    'name' => 'PowerTech Supply Co',
                    'code' => 'SUP-005',
                    'description' => 'Power supplies, UPS units and surge protection for POP sites.',
                    'contact_person' => 'Samuel Rono',
                    'phone' => '+254755666777',
                    'email' => 'orders@powertech.example', 'city' => 'Kisumu', 'country' => 'Kenya',
                    'website' => 'https://powertech.example',
                ],
                [
                    'name' => 'DECO Networking Accessories',
                    'code' => 'SUP-006',
                    'description' => 'RJ45 connectors, patch panels, conduits and installation accessories.',
                    'contact_person' => 'Esther Chebet',
                    'phone' => '+254766777888',
                    'email' => 'sales@deco-accex.example', 'city' => 'Nakuru', 'country' => 'Kenya',
                ],
            ];

            $created = 0;
            foreach ($suppliers as $s) {
                Supplier::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $s['code']],
                    array_merge($s, [
                        'tenant_id' => $tenant->id,
                        'is_active' => true,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} suppliers seeded.");
        });

        $this->command->info('SupplierSeeder: complete.');
    }
}
