<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds tenant announcements. Idempotent on tenant + title.
 */
class AnnouncementSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $announcements = [
                [
                    'title' => 'New fiber coverage area launched',
                    'type' => 'general',
                    'priority' => 'normal',
                    'is_published' => true,
                    'send_notification' => true,
                    'summary' => 'We have extended our fiber network to new estates.',
                    'content' => 'Our fiber network now covers additional estates. Contact sales for connection packages.',
                    'target_audience' => ['clients'],
                    'starts_at' => now()->toDateString(),
                    'ends_at' => now()->addDays(30)->toDateString(),
                ],
                [
                    'title' => 'M-Pesa payments maintenance window',
                    'type' => 'maintenance',
                    'priority' => 'high',
                    'is_published' => true,
                    'send_notification' => true,
                    'summary' => 'M-Pesa payment confirmations may be delayed during maintenance.',
                    'content' => 'Due to scheduled maintenance by our payment partner, payment confirmations may be delayed. Your payments remain safe.',
                    'target_audience' => ['all'],
                    'starts_at' => now()->subDays(1)->toDateString(),
                    'ends_at' => now()->addDays(2)->toDateString(),
                ],
                [
                    'title' => 'Weekend speeds upgrade for all customers',
                    'type' => 'feature',
                    'priority' => 'normal',
                    'is_published' => false,
                    'send_notification' => false,
                    'summary' => 'Automatic speed boost for all plans.',
                    'content' => 'We are upgrading all active connections with a temporary speed boost this weekend.',
                    'target_audience' => ['all'],
                    'starts_at' => now()->addDays(3)->toDateString(),
                    'ends_at' => now()->addDays(6)->toDateString(),
                ],
            ];

            $created = 0;
            foreach ($announcements as $a) {
                Announcement::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'title' => $a['title']],
                    array_merge($a, [
                        'tenant_id' => $tenant->id,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} announcements seeded.");
        });

        $this->command->info('AnnouncementSeeder: complete.');
    }
}
