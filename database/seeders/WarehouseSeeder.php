<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic warehouses per tenant (main hub, POP spares, field kit).
 * Idempotent on the tenant + code pair.
 */
class WarehouseSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $hub = match ($tenant->slug) {
                'primenet-isp' => ['Bungoma HQ', 'Bungoma'],
                'swiftlink-communications' => ['Eldoret HQ', 'Eldoret'],
                'metrowave-internet' => ['Kisumu Hub', 'Kisumu'],
                default => ['Head Office', 'Nairobi'],
            };

            $warehouses = [
                [
                    'name' => $tenant->name . ' Main Warehouse',
                    'code' => 'WH-001',
                    'description' => 'Primary store for network equipment and spares.',
                    'location' => $hub[0],
                    'manager_name' => 'Warehouse Manager',
                    'contact_phone' => '+2547' . str_pad((string) (1000000 + $tenant->id * 3), 8, '0', STR_PAD_LEFT),
                    'contact_email' => $tenant->slug . '.warehouse@primebill.test',
                    'is_active' => true,
                ],
                [
                    'name' => $tenant->name . ' POP Spares',
                    'code' => 'WH-002',
                    'description' => 'Rotating stock staged for POP sites and field deployments.',
                    'location' => $hub[0] . ' - POP',
                    'manager_name' => 'Field Stock Lead',
                    'contact_phone' => '+2547' . str_pad((string) (2000000 + $tenant->id * 3), 8, '0', STR_PAD_LEFT),
                    'contact_email' => $tenant->slug . '.field@primebill.test',
                    'is_active' => true,
                ],
                [
                    'name' => $tenant->name . ' Field Kits',
                    'code' => 'WH-003',
                    'description' => 'Technician kits and installation consumables.',
                    'location' => $hub[1],
                    'manager_name' => 'Installation Lead',
                    'contact_phone' => '+2547' . str_pad((string) (3000000 + $tenant->id * 3), 8, '0', STR_PAD_LEFT),
                    'contact_email' => $tenant->slug . '.install@primebill.test',
                    'is_active' => true,
                ],
            ];

            $created = 0;
            foreach ($warehouses as $w) {
                Warehouse::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $w['code']],
                    array_merge($w, [
                        'tenant_id' => $tenant->id,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} warehouses seeded.");
        });

        $this->command->info('WarehouseSeeder: complete.');
    }
}
