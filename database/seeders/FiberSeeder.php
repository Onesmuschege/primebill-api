<?php

namespace Database\Seeders;

use App\Models\Cabinet;
use App\Models\ClientAccount;
use App\Models\DistributionPoint;
use App\Models\FiberConnection;
use App\Models\FiberRoute;
use App\Models\FiberSplitter;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PonPort;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the fiber access hierarchy: OLTs → PON ports → ONTs, plus routes,
 * splitters, cabinets and distribution points, and the fiber connections
 * binding ONTs to real client accounts. Idempotent via natural unique keys.
 */
class FiberSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $accounts = ClientAccount::where('tenant_id', $tenant->id)->get();
            $created = 0;

            // ── OLTs ────────────────────────────────────────────────────────
            $oltDefs = [
                ['name' => 'OLT-HQ-01', 'vendor' => 'huawei', 'model' => 'MA5680T', 'ip' => '10.10.1.2', 'status' => 'online'],
                ['name' => 'OLT-POP-01', 'vendor' => 'zte', 'model' => 'C320', 'ip' => '10.10.2.2', 'status' => 'maintenance'],
            ];
            foreach ($oltDefs as $o) {
                Olt::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $o['name']],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $o['name'],
                        'vendor' => $o['vendor'],
                        'model' => $o['model'],
                        'ip_address' => $o['ip'],
                        'username' => 'admin',
                        'password' => 'olt_demo',
                        'snmp_community' => 'public',
                        'status' => $o['status'],
                        'location' => 'Central POP',
                        'last_seen' => Carbon::now()->subMinutes(5),
                    ]
                );
                $created++;
            }

            $olts = Olt::where('tenant_id', $tenant->id)->get();

            // ── PON ports ───────────────────────────────────────────────────
            $ponPorts = [];
            foreach ($olts as $olt) {
                for ($i = 0; $i < 4; $i++) {
                    $name = 'gpon-' . ($i + 1) . '/0';
                    $port = PonPort::updateOrCreate(
                        ['olt_id' => $olt->id, 'name' => $name],
                        [
                            'tenant_id' => $tenant->id,
                            'olt_id' => $olt->id,
                            'name' => $name,
                            'technology' => 'gpon',
                            'status' => $i === 3 ? 'faulty' : 'active',
                            'max_onts' => 64,
                            'registered_onts' => $i === 3 ? 0 : 8 + $i,
                        ]
                    );
                    $ponPorts[] = $port;
                    $created++;
                }
            }

            // ── ONTs (bound to client accounts) ─────────────────────────────
            $ontVendors = ['Huawei', 'FiberHome', 'ZTE', 'VSOL'];
            $ontModels = ['EG8145V5', 'AN5506-04', 'F660', 'GP2801'];
            foreach ($accounts->take(16) as $index => $account) {
                $port = $ponPorts[$index % max(1, count($ponPorts))];
                $serial = 'HWTC' . strtoupper(dechex(100000 + $tenant->id * 1000 + $index)) . str_pad((string) ($index % 999), 3, '0', STR_PAD_LEFT);
                Ont::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'serial' => $serial],
                    [
                        'tenant_id' => $tenant->id,
                        'olt_id' => $port->olt_id,
                        'pon_port_id' => $port->id,
                        'serial' => $serial,
                        'mac_address' => '2C:54:91:' . str_pad(dechex($tenant->id + 10), 2, '0', STR_PAD_LEFT) . ':' . str_pad(dechex(intdiv($index, 255)), 2, '0', STR_PAD_LEFT) . ':' . str_pad(dechex($index % 255), 2, '0', STR_PAD_LEFT),
                        'vendor' => $ontVendors[$index % count($ontVendors)],
                        'model' => $ontModels[$index % count($ontModels)],
                        'firmware' => 'V300R013C10SPC120',
                        'rx_signal' => round(-21 + (($index * 13) % 60) / 10, 2),
                        'tx_signal' => round(1.5 + (($index * 7) % 20) / 10, 2),
                        'status' => ['online', 'online', 'offline', 'provisioning'][$index % 4],
                        'last_seen' => Carbon::now()->subMinutes($index * 17),
                        'client_account_id' => $account->id,
                    ]
                );
                $created++;
            }

            // ── Fiber routes ────────────────────────────────────────────────
            $routes = [
                ['name' => 'Route Bungoma - Webuye', 'source' => 'Bungoma HQ', 'destination' => 'Webuye POP', 'length_km' => 12.4, 'status' => 'active'],
                ['name' => 'Route Webuye - Kitale', 'source' => 'Webuye POP', 'destination' => 'Kitale POP', 'length_km' => 48.2, 'status' => 'active'],
                ['name' => 'Route Kisumu - Ahero', 'source' => 'Kisumu Hub', 'destination' => 'Ahero POP', 'length_km' => 18.6, 'status' => 'maintenance'],
            ];
            $routeModels = [];
            foreach ($routes as $r) {
                $route = FiberRoute::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $r['name']],
                    array_merge($r, ['tenant_id' => $tenant->id, 'cable_type' => 'aerial'])
                );
                $routeModels[] = $route;
                $created++;
            }

            // ── Fiber splitters, cabinets, distribution points ──────────────
            foreach (['1:8', '1:16', '1:32'] as $i => $ratio) {
                FiberSplitter::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => 'Splitter-' . ($i + 1)],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => 'Splitter-' . ($i + 1),
                        'split_ratio' => $ratio,
                        'location' => 'Distribution cabinet ' . ($i + 1),
                        'status' => 'active',
                    ]
                );
                $created++;
            }

            for ($i = 0; $i < 3; $i++) {
                Cabinet::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => 'Cabinet-' . ($i + 1)],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => 'Cabinet-' . ($i + 1),
                        'type' => 'distribution',
                        'location' => 'Street cabinet ' . ($i + 1),
                        'status' => 'active',
                        'capacity' => '48U',
                        'notes' => 'Fiber distribution cabinet',
                    ]
                );
                DistributionPoint::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => 'DP-' . ($i + 1)],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => 'DP-' . ($i + 1),
                        'type' => 'fiber_hub',
                        'location' => 'Estate ' . ($i + 1),
                        'status' => 'active',
                    ]
                );
                $created += 2;
            }

            // ── Fiber connections (ONT ↔ client account) ────────────────────
            $splitter = FiberSplitter::where('tenant_id', $tenant->id)->first();
            $distributionPoint = DistributionPoint::where('tenant_id', $tenant->id)->first();
            $route = $routeModels[0] ?? null;
            $connectionStatuses = ['active', 'active', 'installed', 'suspended'];
            foreach ($accounts->take(16) as $index => $account) {
                $ont = Ont::where('tenant_id', $tenant->id)
                    ->where('client_account_id', $account->id)
                    ->first();
                if (! $ont) {
                    continue;
                }
                $status = $connectionStatuses[$index % 4];
                FiberConnection::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'client_account_id' => $account->id],
                    [
                        'tenant_id' => $tenant->id,
                        'client_id' => $account->client_id,
                        'client_account_id' => $account->id,
                        'ont_id' => $ont->id,
                        'pon_port_id' => $ont->pon_port_id,
                        'olt_id' => $ont->olt_id,
                        'fiber_route_id' => $route?->id,
                        'fiber_splitter_id' => $splitter?->id,
                        'distribution_point_id' => $distributionPoint?->id,
                        'status' => $status,
                        'connection_type' => 'ftth',
                        'port_number' => ($index % 16) + 1,
                        'serial_number' => $ont->serial,
                        'mac_address' => $ont->mac_address,
                        'technical_details' => ['split_ratio' => '1:8', 'distance_km' => 2.4],
                        'installation_date' => Carbon::now()->subDays(30 - $index),
                        'notes' => 'Seeded FTTH connection',
                        'created_by' => null,
                        'updated_by' => null,
                    ]
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} fiber records seeded.");
        });

        $this->command->info('FiberSeeder: complete.');
    }
}
