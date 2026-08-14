<?php

namespace Database\Seeders;

use App\Models\ClientAccount;
use App\Models\DhcpLease;
use App\Models\DhcpPool;
use App\Models\IpAllocation;
use App\Models\IpAllocationHistory;
use App\Models\IpPool;
use App\Models\IpReservation;
use App\Models\IpSubnet;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vlan;
use App\Models\VlanAssignment;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds enterprise IPAM: VLANs, IP pools, subnets, allocations (+history),
 * reservations and DHCP pools/leases. Idempotent via natural unique keys.
 */
class IpamSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            $router = Router::where('tenant_id', $tenant->id)->first();
            $accounts = ClientAccount::where('tenant_id', $tenant->id)->get();

            $created = 0;

            // ── VLANs ───────────────────────────────────────────────────────
            $vlans = [
                ['vlan_id' => 10, 'name' => 'Management', 'description' => 'Infrastructure management network'],
                ['vlan_id' => 20, 'name' => 'Customer-PPPoE', 'description' => 'Customer PPPoE access network'],
                ['vlan_id' => 30, 'name' => 'Hotspot', 'description' => 'Public hotspot services'],
                ['vlan_id' => 40, 'name' => 'Backbone', 'description' => 'Core backbone links'],
                ['vlan_id' => 50, 'name' => 'Guest', 'description' => 'Guest / test network'],
            ];

            foreach ($vlans as $v) {
                Vlan::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'vlan_id' => $v['vlan_id']],
                    array_merge($v, [
                        'tenant_id' => $tenant->id,
                        'router_id' => $router?->id,
                        'status' => 'active',
                    ])
                );
                $created++;
            }

            $vlan10 = Vlan::where('tenant_id', $tenant->id)->where('vlan_id', 10)->first();
            $vlan20 = Vlan::where('tenant_id', $tenant->id)->where('vlan_id', 20)->first();

            // ── IP Pools ────────────────────────────────────────────────────
            $octet3 = 100 + ($tenant->id * 10);
            $pools = [
                ['name' => 'Customer PPPoE Pool', 'network' => '10.' . $octet3 . '.0.0', 'prefix' => 22, 'gateway' => '10.' . $octet3 . '.0.1', 'vlan' => $vlan20, 'is_public' => false],
                ['name' => 'Hotspot Pool', 'network' => '172.16.' . $octet3 . '.0.0', 'prefix' => 24, 'gateway' => '172.16.' . $octet3 . '.0.1', 'vlan' => null, 'is_public' => true],
                ['name' => 'Management Pool', 'network' => '192.168.' . (100 + $tenant->id) . '.0.0', 'prefix' => 24, 'gateway' => '192.168.' . (100 + $tenant->id) . '.0.1', 'vlan' => $vlan10, 'is_public' => false],
            ];

            $poolModels = [];
            foreach ($pools as $p) {
                $pool = IpPool::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $p['name']],
                    [
                        'tenant_id' => $tenant->id,
                        'family' => 'ipv4',
                        'network' => $p['network'],
                        'prefix' => $p['prefix'],
                        'gateway' => $p['gateway'],
                        'dns_primary' => '8.8.8.8',
                        'dns_secondary' => '1.1.1.1',
                        'is_public' => $p['is_public'],
                        'status' => 'active',
                        'description' => $p['name'],
                        'vlan_id' => $p['vlan']?->id,
                        'router_id' => $router?->id,
                    ]
                );
                $poolModels[] = $pool;
                $created++;
            }

            // ── Subnets ─────────────────────────────────────────────────────
            $pool = $poolModels[0] ?? null;
            $subnets = [
                ['name' => 'Subscriber Segment A', 'cidr' => '10.' . $octet3 . '.0.0/24', 'network' => '10.' . $octet3 . '.0.0', 'prefix' => 24, 'gateway' => '10.' . $octet3 . '.0.1', 'vlan' => $vlan20, 'pool' => $pool],
                ['name' => 'Subscriber Segment B', 'cidr' => '10.' . $octet3 . '.1.0/24', 'network' => '10.' . $octet3 . '.1.0', 'prefix' => 24, 'gateway' => '10.' . $octet3 . '.1.1', 'vlan' => $vlan20, 'pool' => $pool],
            ];

            $subnetModels = [];
            foreach ($subnets as $s) {
                $subnet = IpSubnet::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'cidr' => $s['cidr']],
                    [
                        'tenant_id' => $tenant->id,
                        'ip_pool_id' => $s['pool']?->id,
                        'name' => $s['name'],
                        'family' => 'ipv4',
                        'cidr' => $s['cidr'],
                        'network' => $s['network'],
                        'prefix' => $s['prefix'],
                        'gateway' => $s['gateway'],
                        'is_public' => false,
                        'status' => 'active',
                        'description' => $s['name'],
                        'vlan_id' => $s['vlan']?->id,
                    ]
                );
                $subnetModels[] = $subnet;
                $created++;
            }

            // ── Allocations (bound to real client accounts) ─────────────────
            $allocationStatuses = ['allocated', 'allocated', 'reserved', 'released'];
            foreach ($accounts->take(12) as $index => $account) {
                $ip = '10.' . $octet3 . '.' . intdiv($index, 240) . '.' . (($index % 239) + 10);
                $status = $allocationStatuses[$index % 4];

                $allocation = IpAllocation::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'ip_address' => $ip, 'status' => $status],
                    [
                        'tenant_id' => $tenant->id,
                        'ip_pool_id' => $pool?->id,
                        'ip_subnet_id' => ($subnetModels[$index % max(1, count($subnetModels))] ?? null)?->id,
                        'ip_address' => $ip,
                        'family' => 'ipv4',
                        'status' => $status,
                        'client_id' => $account->client_id,
                        'client_account_id' => $account->id,
                        'vlan_id' => $vlan20?->id,
                        'hostname' => 'cpe-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'description' => 'PPPoE allocation for ' . $account->username,
                        'allocated_at' => Carbon::now()->subDays(30 - $index),
                        'released_at' => $status === 'released' ? Carbon::now()->subDays(3) : null,
                    ]
                );

                IpAllocationHistory::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'ip_allocation_id' => $allocation->id, 'action' => $status],
                    [
                        'tenant_id' => $tenant->id,
                        'ip_address' => $ip,
                        'client_id' => $account->client_id,
                        'client_account_id' => $account->id,
                        'user_id' => $admin?->id,
                        'description' => 'Seeded ' . $status . ' event for ' . $ip,
                        'created_at' => $allocation->allocated_at ?? Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]
                );
                $created++;
            }

            // ── Reservations ────────────────────────────────────────────────
            for ($i = 0; $i < 3; $i++) {
                $account = $accounts[$i] ?? null;
                $ip = '10.' . $octet3 . '.0.' . (200 + $i);
                IpReservation::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'ip_address' => $ip],
                    [
                        'tenant_id' => $tenant->id,
                        'ip_pool_id' => $pool?->id,
                        'ip_subnet_id' => $subnetModels[0]->id ?? null,
                        'ip_address' => $ip,
                        'family' => 'ipv4',
                        'hostname' => 'server-' . ($i + 1),
                        'client_id' => $account?->client_id,
                        'client_account_id' => $account?->id,
                        'description' => 'Static reservation for infrastructure server ' . ($i + 1),
                    ]
                );
                $created++;
            }

            // ── DHCP pools & leases ─────────────────────────────────────────
            $dhcp = DhcpPool::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Hotspot DHCP Pool'],
                [
                    'tenant_id' => $tenant->id,
                    'ip_pool_id' => $poolModels[1]->id ?? null,
                    'name' => 'Hotspot DHCP Pool',
                    'range_start' => '172.16.' . $octet3 . '.10',
                    'range_end' => '172.16.' . $octet3 . '.250',
                    'gateway' => '172.16.' . $octet3 . '.0.1',
                    'dns_primary' => '8.8.8.8',
                    'dns_secondary' => '1.1.1.1',
                    'lease_time' => '24h',
                    'status' => 'active',
                    'description' => 'Public hotspot address pool',
                ]
            );
            $created++;

                        foreach ($accounts->take(6) as $i => $account) {
                DhcpLease::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'dhcp_pool_id' => $dhcp->id, 'ip_address' => '172.16.' . $octet3 . '.' . (20 + $i)],
                    [
                        'tenant_id' => $tenant->id,
                        'dhcp_pool_id' => $dhcp->id,
                        'ip_address' => '172.16.' . $octet3 . '.' . (20 + $i),
                        'mac_address' => '00:1' . (1 + $tenant->id) . ':00:1' . ($i + 1) . ':a' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                        'hostname' => 'hotspot-user-' . ($i + 1),
                        'lease_start' => Carbon::now()->subHours(5),
                        'lease_end' => Carbon::now()->addHours(19),
                        'status' => 'active',
                    ]
                );
                $created++;
            }

            // ── VLAN assignments ────────────────────────────────────────────
            VlanAssignment::updateOrCreate(
                ['tenant_id' => $tenant->id, 'vlan_id' => $vlan20?->id, 'assignable_type' => 'ip_pool', 'assignable_id' => $pool?->id],
                [
                    'tenant_id' => $tenant->id,
                    'vlan_id' => $vlan20?->id,
                    'assignable_type' => 'ip_pool',
                    'assignable_id' => $pool?->id,
                    'is_trunk' => false,
                    'description' => 'Customer PPPoE VLAN on subscriber pool',
                ]
            );
            $created++;

            $this->command->line("  [{$tenant->slug}] {$created} IPAM records seeded.");
        });

        $this->command->info('IpamSeeder: complete.');
    }
}
