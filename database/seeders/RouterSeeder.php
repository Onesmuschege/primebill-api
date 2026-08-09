<?php

namespace Database\Seeders;

use App\Models\Router;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds multiple MikroTik routers per tenant, linked to the tenant via the
 * BelongsToTenant trait (current tenant binding). Routers also carry the
 * NOC and Network-Core NAS/RADIUS columns.
 */
class RouterSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $base = [
                'primenet-isp' => [
                    ['name' => 'Edge-Router-01', 'ip' => '10.0.0.1', 'location' => 'Bungoma HQ', 'status' => 'online'],
                    ['name' => 'Core-Router-01', 'ip' => '10.0.1.1', 'location' => 'Bungoma HQ', 'status' => 'online'],
                    ['name' => 'Kakamega-Router', 'ip' => '10.0.2.1', 'location' => 'Kakamega POP', 'status' => 'online'],
                    ['name' => 'Kisumu-Router',   'ip' => '10.0.3.1', 'location' => 'Kisumu POP', 'status' => 'online'],
                    ['name' => 'Eldoret-Router',  'ip' => '10.0.4.1', 'location' => 'Eldoret POP', 'status' => 'offline'],
                ],
                'swiftlink-communications' => [
                    ['name' => 'SL-Edge-01',      'ip' => '172.16.0.1', 'location' => 'Eldoret HQ', 'status' => 'online'],
                    ['name' => 'SL-Core-01',      'ip' => '172.16.1.1', 'location' => 'Eldoret HQ', 'status' => 'online'],
                    ['name' => 'SL-Webuye-POP',   'ip' => '172.16.2.1', 'location' => 'Webuye', 'status' => 'online'],
                    ['name' => 'SL-Kitale-POP',   'ip' => '172.16.3.1', 'location' => 'Kitale', 'status' => 'offline'],
                ],
                'metrowave-internet' => [
                    ['name' => 'MW-Gateway-01',  'ip' => '192.168.50.1', 'location' => 'Kisumu Hub', 'status' => 'online'],
                    ['name' => 'MW-Core-01',     'ip' => '192.168.51.1', 'location' => 'Kisumu Hub', 'status' => 'online'],
                ],
            ];

            $routers = $base[$tenant->slug] ?? [];

            foreach ($routers as $r) {
                Router::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $r['name']],
                    [
                        'ip_address'   => $r['ip'],
                        'username'     => 'admin',
                        'password'     => 'mikrotik_demo',
                        'port'         => 8728,
                        'type'         => 'mikrotik',
                        'device_type'  => 'router',
                        'model'        => 'CCR1036-8G-2S+',
                        'vendor'       => 'MikroTik',
                        'location'     => $r['location'],
                        'status'       => $r['status'],
                        'last_seen'    => now(),
                        'snmp_version' => '2c',
                        'snmp_port'    => 161,
                        'radius_ip'    => $r['ip'],
                        'nas_identifier'=> $r['name'],
                        'nas_type'     => 'mikrotik',
                        'routeros_version' => '7.14',
                        'capabilities' => ['pppoe', 'hotspot', 'dhcp', 'static_ip', 'coa', 'disconnect'],
                    ]
                );
            }
        });

        $this->command->info('RouterSeeder: 3 tenants, multiple routers each.');
    }
}
