<?php

namespace Database\Seeders;

use App\Models\Discount;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DiscountSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $discounts = [
                ['name' => 'New Customer 20% Off', 'code' => 'WELCOME20', 'type' => 'percentage', 'value' => 20.00, 'is_active' => true],
                ['name' => 'Loyalty Ksh 500 Off', 'code' => 'LOYAL500', 'type' => 'fixed', 'value' => 500.00, 'is_active' => true],
            ];

            foreach ($discounts as $discount) {
                Discount::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $discount['code']],
                    array_merge($discount, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($discounts) . " discounts seeded.");
        });

        $this->command->info('DiscountSeeder: complete.');
    }
}
