<?php

namespace Database\Seeders;

use App\Models\NetworkAlert;
use App\Models\NetworkIncident;
use App\Models\Tenant;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class NetworkOperationsSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $adminId = \App\Models\User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->value('id');

            $device = \App\Models\Device::where('tenant_id', $tenant->id)->first();

            NetworkIncident::create([
                'tenant_id' => $tenant->id,
                'created_by' => $adminId ?? 1,
                'title' => 'Link degradation at North POP',
                'severity' => 'major',
                'status' => 'resolved',
                'detected_at' => Carbon::now()->subDays(3),
                'created_at' => Carbon::now()->subDays(3),
                'updated_at' => Carbon::now()->subDays(2),
            ]);

            if ($device) {
                NetworkAlert::create([
                    'tenant_id' => $tenant->id,
                    'device_id' => $device->id,
                    'alert_type' => 'high_util',
                    'severity' => 'warning',
                    'message' => 'High bandwidth utilization detected',
                    'created_at' => Carbon::now()->subHours(3),
                ]);
            }

            $this->command->line("  [{$tenant->slug}] Network incidents and alerts seeded.");
        });

        $this->command->info('NetworkOperationsSeeder: complete.');
    }
}
