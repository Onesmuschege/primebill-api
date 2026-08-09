<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds a PPPoE/hotspot account for each client, tenant-aware. Usernames are
 * globally unique (combined with the tenant slug) to satisfy the DB unique
 * constraint while keeping per-tenant identity clear. Also populates the
 * Network-Core service lifecycle fields (service_state, access_method,
 * entitled_until, is_entitled).
 */
class ClientAccountSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $plans  = Plan::where('tenant_id', $tenant->id)->get();
            $router = Router::where('tenant_id', $tenant->id)->first();
            $counter = 0;

            Client::where('tenant_id', $tenant->id)->each(function (Client $client) use ($tenant, $plans, $router, &$counter) {
                if (ClientAccount::where('tenant_id', $tenant->id)->where('client_id', $client->id)->exists()) {
                    return;
                }

                $plan = $plans[$client->id % max(1, $plans->count())];

                [$accountStatus, $expiryDate, $activatedAt, $serviceState] = $this->derive($client->status);

                $octet3 = intdiv($counter, 250) + 10;
                $octet4 = ($counter % 249) + 2;

                ClientAccount::create([
                    'client_id'        => $client->id,
                    'plan_id'          => $plan->id,
                    'username'         => 'pb_' . $tenant->slug . '_' . strtolower($client->first_name) . str_pad((string) $client->id, 3, '0', STR_PAD_LEFT),
                    'password'         => Str::random(12),
                    'ip_address'       => "10.10.{$octet3}.{$octet4}",
                    'mac_address'      => $this->fakeMac($client->id),
                    'type'             => 'prepaid',
                    'status'           => $accountStatus,
                    'expiry_date'      => $expiryDate,
                    'activated_at'     => $activatedAt,
                    'access_method'    => $plan->type === 'hotspot' ? 'hotspot' : 'pppoe',
                    'nas_id'           => $router?->id,
                    'service_state'    => $serviceState,
                    'provisioned_at'   => $activatedAt,
                    'suspended_at'     => $serviceState === 'SUSPENDED' ? now()->subDays(rand(1, 15)) : null,
                    'restored_at'      => $serviceState === 'ACTIVE' ? $activatedAt : null,
                    'entitled_until'   => $serviceState === 'ACTIVE' ? now()->addDays(rand(5, 30)) : null,
                    'is_entitled'      => $serviceState === 'ACTIVE',
                ]);

                $counter++;
            });
        });

        $this->command->info('ClientAccountSeeder: PPPoE/hotspot accounts seeded per tenant.');
    }

    private function derive(string $clientStatus): array
    {
        return match ($clientStatus) {
            'active'    => ['active',    Carbon::now()->addDays(rand(5, 30)), Carbon::now()->subDays(rand(1, 60)), 'ACTIVE'],
            'suspended' => ['suspended', Carbon::now()->subDays(rand(1, 15)),   Carbon::now()->subDays(rand(30, 90)), 'SUSPENDED'],
            'inactive'  => ['expired',   Carbon::now()->subDays(rand(30, 90)),  Carbon::now()->subDays(rand(90, 180)), 'TERMINATED'],
            'disabled'  => ['suspended', Carbon::now()->subDays(rand(60, 120)), Carbon::now()->subDays(rand(120, 365)), 'SUSPENDED'],
            default     => ['active',    Carbon::now()->addDays(15),            Carbon::now()->subDays(10), 'ACTIVE'],
        };
    }

    private function fakeMac(int $seed): string
    {
        $parts = [];
        $val   = ($seed + 1) * 2654435761;
        for ($i = 0; $i < 6; $i++) {
            $parts[] = strtoupper(sprintf("%02X", ($val >> ($i * 4)) & 0xFF));
        }
        $parts[0] = strtoupper(sprintf("%02X", hexdec($parts[0]) | 0x02));
        return implode(':', $parts);
    }
}
