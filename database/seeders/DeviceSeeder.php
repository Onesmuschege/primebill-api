<?php

namespace Database\Seeders;

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
            $devices = [
                ['name' => 'OLT-KL-01', 'type' => 'OLT', 'vendor' => 'Huawei', 'model' => 'MA5680T', 'ip_address' => '10.10.10.1', 'status' => 'active'],
                ['name' => 'OLT-KL-02', 'type' => 'OLT', 'vendor' => 'ZTE', 'model' => 'C320', 'ip_address' => '10.10.10.2', 'status' => 'active'],
                ['name' => 'Switch-Core-01', 'type' => 'Switch', 'vendor' => 'Cisco', 'model' => 'Catalyst 9300', 'ip_address' => '10.10.10.3', 'status' => 'active'],
                ['name' => 'Switch-Access-01', 'type' => 'Switch', 'vendor' => 'TP-Link', 'model' => 'TL-SG3424', 'ip_address' => '10.10.10.4', 'status' => 'active'],
            ];

            foreach ($devices as $device) {
                Device::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'name' => $device['name']],
                    array_merge($device, ['tenant_id' => $tenant->id])
                );
            }

            $this->command->line("  [{$tenant->slug}] " . count($devices) . " devices seeded.");
        });

        $this->command->info('DeviceSeeder: complete.');
    }
}