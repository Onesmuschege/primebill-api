<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds marketing/support campaigns with recipients referencing real
 * clients. Idempotent on tenant + code.
 */
class CampaignSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            $clients = Client::where('tenant_id', $tenant->id)->where('status', 'active')->get();

            $campaigns = [
                [
                    'code' => 'PROMOTION', 'name' => 'Weekend Speed Boost', 'type' => 'sms', 'category' => 'marketing',
                    'status' => 'sent', 'priority' => 'normal',
                    'subject' => null,
                    'content' => 'Enjoy a double-speed boost this weekend on {plan}! T&Cs apply.',
                    'total_recipients' => min(20, $clients->count()),
                    'sent_count' => min(20, $clients->count()),
                    'delivered_count' => min(18, $clients->count()),
                    'failed_count' => 1,
                    'sent_at' => Carbon::now()->subDays(4)->setTime(9, 0),
                    'scheduled_at' => Carbon::now()->subDays(5),
                ],
                [
                    'code' => 'MAINTENANCE', 'name' => 'Planned Maintenance Notice', 'type' => 'email', 'category' => 'support',
                    'status' => 'draft', 'priority' => 'high',
                    'subject' => 'Upcoming maintenance ({start} — {end})',
                    'content' => 'Dear {name}, scheduled maintenance will occur from {start} to {end}.',
                    'total_recipients' => 0, 'sent_count' => 0, 'delivered_count' => 0, 'failed_count' => 0,
                    'scheduled_at' => Carbon::now()->addDays(1), 'sent_at' => null,
                ],
                [
                    'code' => 'WELCOME', 'name' => 'New Customer Welcome', 'type' => 'sms', 'category' => 'marketing',
                    'status' => 'sent', 'priority' => 'normal',
                    'subject' => null,
                    'content' => 'Welcome to {brand}, {name}! Your connection is active.',
                    'total_recipients' => 8, 'sent_count' => 8, 'delivered_count' => 7, 'failed_count' => 0,
                    'scheduled_at' => Carbon::now()->subDays(12), 'sent_at' => Carbon::now()->subDays(12)->setTime(11, 0),
                ],
            ];

            $created = 0;
            $recipients = 0;

            foreach ($campaigns as $c) {
                $campaign = Campaign::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $c['code']],
                    array_merge($c, [
                        'tenant_id' => $tenant->id,
                        'content_plain' => $c['content'],
                        'target_audience' => ['all'],
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );

                if ($campaign->wasRecentlyCreated && $clients->isNotEmpty() && $c['status'] === 'sent') {
                    $take = min($c['total_recipients'], $clients->count());
                    for ($i = 0; $i < $take; $i++) {
                        $client = $clients[$i];
                        $recipientStatus = $i === $campaigns[0]['failed_count'] ? 'failed' : 'delivered';
                        CampaignRecipient::firstOrCreate(
                            ['campaign_id' => $campaign->id, 'recipient_type' => 'client', 'recipient_id' => $client->id],
                            [
                                'tenant_id' => $tenant->id,
                                'recipient_address' => $client->email,
                                'status' => $recipientStatus,
                                'provider' => $c['type'],
                                'sent_at' => $c['sent_at'],
                                'delivered_at' => $recipientStatus === 'delivered' ? $c['sent_at']->copy()->addMinutes(2) : null,
                                'metadata' => ['seed' => 'campaign-recipient-' . $campaign->id . '-' . $i],
                            ]
                        );
                        $recipients++;
                    }
                }

                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} campaigns and {$recipients} recipients seeded.");
        });

        $this->command->info('CampaignSeeder: complete.');
    }
}
