<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class TaxSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $taxes = [
                ['name' => 'VAT 16%', 'rate' => 16.00, 'type' => 'percentage', 'is_active' => true],
                ['name' => 'Service Tax', 'rate' => 2.00, 'type' => 'percentage', 'is_active' => true],
            ];

            foreach ($taxes as $tax) {
                TaxRate::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $tax['name']],
                    array_merge($tax, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($taxes) . " tax rates seeded.");
        });

        $this->command->info('TaxSeeder: complete.');
    }
}
