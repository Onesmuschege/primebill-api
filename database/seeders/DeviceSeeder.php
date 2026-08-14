<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Device;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

class DeviceSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $clients = Client::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->get();

            if ($clients->isEmpty()) {
                $this->command->warn("DeviceSeeder [{$tenant->slug}]: No active clients found. Skipping.");
                return;
            }

            $devices = [
                ['device_name' => 'OLT-KL-01',        'device_type' => 'OLT',    'vendor' => 'Huawei', 'ip_address' => '10.10.10.1', 'status' => 'active'],
                ['device_name' => 'OLT-KL-02',        'device_type' => 'OLT',    'vendor' => 'ZTE',    'ip_address' => '10.10.10.2', 'status' => 'active'],
                ['device_name' => 'Switch-Core-01',   'device_type' => 'Switch', 'vendor' => 'Cisco',  'ip_address' => '10.10.10.3', 'status' => 'active'],
                ['device_name' => 'Switch-Access-01', 'device_type' => 'Switch', 'vendor' => 'TP-Link','ip_address' => '10.10.10.4', 'status' => 'active'],
            ];

            foreach ($devices as $i => $device) {
                $client = $clients[$i % $clients->count()];
                Device::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'device_name' => $device['device_name']],
                    array_merge($device, [
                        'client_id'     => $client->id,
                        'first_seen_at' => now()->subDays(rand(1, 60)),
                        'last_seen_at'  => now(),
                    ])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($devices) . " devices seeded.");
        });

        $this->command->info('DeviceSeeder: complete.');
    }
}