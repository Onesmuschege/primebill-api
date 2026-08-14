<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds known user devices per tenant. Only uses the columns actually
 * present on the user_devices table (device_type / device_name /
 * is_trusted / last_used_at). Idempotent guard.
 */
class UserDeviceSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $users = User::where('tenant_id', $tenant->id)->get();
            if ($users->isEmpty()) {
                $this->command->warn("UserDeviceSeeder [{$tenant->slug}]: No users found. Skipping.");
                return;
            }

            if (UserDevice::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] User devices already present — skipped.");
                return;
            }

            $devices = [
                ['device_type' => 'desktop', 'device_name' => 'Workstation Chrome', 'is_trusted' => true],
                ['device_type' => 'laptop', 'device_name' => 'MacBook Safari', 'is_trusted' => true],
                ['device_type' => 'mobile', 'device_name' => 'Android Chrome', 'is_trusted' => false],
                ['device_type' => 'mobile', 'device_name' => 'iPhone Safari', 'is_trusted' => false],
            ];

            $created = 0;
            foreach ($users as $uIndex => $user) {
                foreach ($devices as $dIndex => $device) {
                    UserDevice::create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'device_type' => $device['device_type'],
                        'device_name' => $device['device_name'],
                        'is_trusted' => $device['is_trusted'],
                        'last_used_at' => Carbon::now()->subDays(($uIndex * 5 + $dIndex) % 40),
                    ]);
                    $created++;
                }
            }

            $this->command->line("  [{$tenant->slug}] {$created} user devices seeded.");
        });

        $this->command->info('UserDeviceSeeder: complete.');
    }
}
