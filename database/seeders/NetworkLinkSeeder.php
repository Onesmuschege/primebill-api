<?php

namespace Database\Seeders;

use App\Models\NetworkLink;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class NetworkLinkSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            NetworkLink::create([
                'tenant_id' => $tenant->id,
                'name' => 'Kisumu-Kakamega',
                'source_node' => 'Kisumu POP',
                'target_node' => 'Kakamega POP',
                'capacity_mbps' => 1000,
                'status' => 'active',
            ]);

            $this->command->line("  [{$tenant->slug}] Network link seeded.");
        });

        $this->command->info('NetworkLinkSeeder: complete.');
    }
}
