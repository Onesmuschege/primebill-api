<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic Kenyan ISP plans per tenant. Plans are tenant-owned and
 * reference a router of that tenant. Names are prefixed with the tenant slug
 * so the per-tenant unique constraints stay clean and cross-tenant isolation
 * is obvious.
 */
class PlanSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $router = Router::where('tenant_id', $tenant->id)->first();

            $prefix = $tenant->slug;

            $plans = [
                [$prefix . ': Home Basic 10Mbps',  'pppoe', 10240,   10240,   1500.00],
                [$prefix . ': Home Standard 30Mbps','pppoe', 30720,  30720,   2500.00],
                [$prefix . ': Home Premium 50Mbps', 'pppoe', 51200,  51200,   4500.00],
                [$prefix . ': Business 100Mbps',    'pppoe', 102400, 102400,  8000.00],
                [$prefix . ': Business Pro 150Mbps', 'pppoe', 153600, 153600, 15000.00],
                [$prefix . ': Hotspot 1 Hour',      'hotspot', 10240, 10240,   10.00],
                [$prefix . ': Hotspot 24 Hours',    'hotspot', 10240, 10240,   30.00],
            ];

            foreach ($plans as [$name, $type, $up, $down, $price]) {
                Plan::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $name],
                    [
                        'type'           => $type,
                        'speed_up'       => $up,
                        'speed_down'     => $down,
                        'burst_up'       => (int) ($up / 4),
                        'burst_down'     => (int) ($down / 4),
                        'fup_limit'      => $type === 'pppoe' ? 50 : null,
                        'fup_speed_up'   => 1024,
                        'fup_speed_down' => 1024,
                        'validity_days'  => $type === 'hotspot' ? 1 : 30,
                        'price'          => $price,
                        'router_id'      => $router?->id,
                        'is_active'      => true,
                    ]
                );
            }
        });

        $this->command->info('PlanSeeder: plans seeded per tenant.');
    }
}
