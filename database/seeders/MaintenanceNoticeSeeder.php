<?php

namespace Database\Seeders;

use App\Models\MaintenanceNotice;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds scheduled/emergency/completed maintenance notices per tenant.
 * Idempotent on tenant + title.
 */
class MaintenanceNoticeSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $notices = [
                [
                    'title' => 'Core Router Firmware Upgrade',
                    'type' => 'scheduled',
                    'severity' => 'medium',
                    'is_published' => true,
                    'send_notification' => true,
                    'summary' => 'Scheduled firmware upgrade of core routers to improve stability.',
                    'description' => 'We will be upgrading firmware on core network devices. Intermittent connectivity may be experienced during the window.',
                    'impact_description' => 'Brief connectivity blips of up to 5 minutes per device.',
                    'affected_services' => ['pppoe', 'hotspot', 'static_ip'],
                    'affected_areas' => ['Bungoma Town', 'Webuye'],
                    'starts_at' => Carbon::now()->addDays(2)->startOfDay()->addHours(2),
                    'ends_at' => Carbon::now()->addDays(2)->startOfDay()->addHours(5),
                    'completed_at' => null,
                ],
                [
                    'title' => 'Fiber Backbone Maintenance',
                    'type' => 'scheduled',
                    'severity' => 'high',
                    'is_published' => true,
                    'send_notification' => true,
                    'summary' => 'Scheduled splicing works on the backbone fiber route.',
                    'description' => 'Planned splicing and re-termination works on the regional backbone fiber.',
                    'impact_description' => 'Service interruption for customers on the backbone segment.',
                    'affected_services' => ['fiber'],
                    'affected_areas' => ['Kisumu', 'Ahero'],
                    'starts_at' => Carbon::now()->addDays(5)->startOfDay()->addHours(22),
                    'ends_at' => Carbon::now()->addDays(6)->startOfDay()->addHours(4),
                    'completed_at' => null,
                ],
                [
                    'title' => 'Emergency UPS Replacement at HQ POP',
                    'type' => 'emergency',
                    'severity' => 'critical',
                    'is_published' => true,
                    'send_notification' => true,
                    'summary' => 'Emergency replacement of a failed UPS unit.',
                    'description' => 'A UPS unit at the HQ POP failed. Emergency replacement is underway with a standby generator covering power.',
                    'impact_description' => 'Reduced redundancy; risk of short outage if generator fails.',
                    'affected_services' => ['pppoe', 'hotspot'],
                    'affected_areas' => ['HQ POP'],
                    'starts_at' => Carbon::now()->subHours(6),
                    'ends_at' => Carbon::now()->addHours(6),
                    'completed_at' => null,
                ],
                [
                    'title' => 'Completed: Network Capacity Upgrade',
                    'type' => 'completed',
                    'severity' => 'medium',
                    'is_published' => true,
                    'send_notification' => false,
                    'summary' => 'Capacity upgrade on congested nodes is complete.',
                    'description' => 'Added additional bandwidth and upgraded switching at congested nodes.',
                    'impact_description' => 'Improved speeds during peak hours.',
                    'affected_services' => ['pppoe'],
                    'affected_areas' => ['City Core'],
                    'starts_at' => Carbon::now()->subDays(7)->startOfDay()->addHours(1),
                    'ends_at' => Carbon::now()->subDays(7)->startOfDay()->addHours(6),
                    'completed_at' => Carbon::now()->subDays(6),
                ],
            ];

            $created = 0;
            foreach ($notices as $n) {
                MaintenanceNotice::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'title' => $n['title']],
                    array_merge($n, [
                        'tenant_id' => $tenant->id,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} maintenance notices seeded.");
        });

        $this->command->info('MaintenanceNoticeSeeder: complete.');
    }
}
