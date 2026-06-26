<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds 10 support tickets across different clients.
 * Mix of open, pending, and solved statuses.
 * Solved and pending tickets get a staff reply.
 */
class TicketSeeder extends Seeder
{
    private array $tickets = [
        [
            'subject'     => 'Internet connection is down',
            'description' => 'My internet has been completely down since this morning around 8am. I have restarted the router three times but it is still not connecting. The router lights show no WAN connection.',
            'priority'    => 'high',
            'status'      => 'open',
            'reply'       => null,
        ],
        [
            'subject'     => 'Slow speeds during peak hours',
            'description' => 'Between 6pm and 10pm daily, my speeds drop from the expected 10Mbps to below 1Mbps. I have run speed tests and it is consistently slow at those hours. This has been happening for two weeks.',
            'priority'    => 'medium',
            'status'      => 'pending',
            'reply'       => 'Thank you for reaching out. We have identified congestion on your node during peak hours. Our network team is working on a capacity upgrade scheduled for this weekend. We will notify you once resolved.',
        ],
        [
            'subject'     => 'Router keeps disconnecting every 30 minutes',
            'description' => 'The connection drops every 30 minutes and reconnects. This is very disruptive to my work from home. I have tried a different cable and the problem persists.',
            'priority'    => 'high',
            'status'      => 'solved',
            'reply'       => 'We have investigated and found a session timeout misconfiguration on your account profile. This has been corrected on our MikroTik router. Please confirm your connection is now stable.',
        ],
        [
            'subject'     => 'Request to upgrade to Business 20Mbps plan',
            'description' => 'I would like to upgrade from my current Home Standard 5Mbps plan to the Business 20Mbps plan. Please advise on the cost difference and how to proceed.',
            'priority'    => 'low',
            'status'      => 'solved',
            'reply'       => 'Your account has been upgraded to Business 20Mbps effective today. The prorated difference of Ksh 1,740 has been added to your next invoice. Your new speeds are now active.',
        ],
        [
            'subject'     => 'Billing query — double charge in May',
            'description' => 'I noticed two payments of Ksh 2,900 were deducted from my M-Pesa in May. Reference QGH4R7KM2X and QJK9P3RM4V. Please confirm and refund the duplicate.',
            'priority'    => 'high',
            'status'      => 'pending',
            'reply'       => 'We have reviewed your payment history and confirmed the duplicate charge. A credit of Ksh 2,900 has been applied to your account and will reflect on your next invoice as a deduction.',
        ],
        [
            'subject'     => 'PPPoE credentials stopped working',
            'description' => 'My PPPoE login stopped working suddenly yesterday evening. I have not changed any settings. My username is pb_james001. Please reset or confirm my credentials.',
            'priority'    => 'medium',
            'status'      => 'solved',
            'reply'       => 'Your PPPoE credentials have been verified and reset. Your new password has been sent to your registered phone number via SMS. Please reconnect using the new credentials.',
        ],
        [
            'subject'     => 'Cannot connect new laptop to internet',
            'description' => 'I bought a new laptop and cannot get it to connect. My phone and other devices are working fine on the same router. I have tried forgetting the network and reconnecting.',
            'priority'    => 'low',
            'status'      => 'open',
            'reply'       => null,
        ],
        [
            'subject'     => 'Static IP address needed for CCTV system',
            'description' => 'I need a static IP address configured for my CCTV system so I can access it remotely. Please advise on cost and configuration process.',
            'priority'    => 'medium',
            'status'      => 'pending',
            'reply'       => 'Static IP configuration is available at an additional Ksh 500 per month. We will configure this on your account and send the IP details once payment is confirmed. Please approve via reply.',
        ],
        [
            'subject'     => 'Request for payment receipt — M-Pesa transaction',
            'description' => 'Please send me an official receipt for the M-Pesa payment I made on 15th June 2026, reference QMP7T9KL3R, amount Ksh 4,640.',
            'priority'    => 'low',
            'status'      => 'solved',
            'reply'       => 'Your official receipt for Invoice INV-2026-000045 has been generated and sent to your email address on file. You can also download it from your client portal.',
        ],
        [
            'subject'     => 'Wi-Fi signal does not reach second floor',
            'description' => 'The Wi-Fi signal from the router installed downstairs does not reach my second floor bedroom and office. I need assistance with extending the coverage.',
            'priority'    => 'low',
            'status'      => 'open',
            'reply'       => null,
        ],
    ];

    public function run(): void
    {
        $admin   = User::where('email', 'admin@primebill.co.ke')->first();
        $clients = Client::where('status', 'active')->get();

        if ($clients->isEmpty()) {
            $this->command->warn('TicketSeeder: No active clients found. Skipping.');
            return;
        }

        $count = 0;

        foreach ($this->tickets as $index => $data) {
            $client    = $clients[$index % $clients->count()];
            $createdAt = Carbon::now()->subDays(rand(1, 45));
            $closedAt  = $data['status'] === 'solved'
                ? $createdAt->copy()->addHours(rand(4, 72))
                : null;

            $ticket = Ticket::create([
                'client_id'   => $client->id,
                'assigned_to' => $admin->id,
                'subject'     => $data['subject'],
                'description' => $data['description'],
                'priority'    => $data['priority'],
                'status'      => $data['status'],
                'closed_at'   => $closedAt,
                'created_at'  => $createdAt,
                'updated_at'  => $closedAt ?? $createdAt,
            ]);

            if ($data['reply']) {
                TicketReply::create([
                    'ticket_id'   => $ticket->id,
                    'user_id'     => $admin->id,
                    'message'     => $data['reply'],
                    'is_internal' => false,
                    'created_at'  => $createdAt->copy()->addHours(rand(1, 12)),
                    'updated_at'  => $createdAt->copy()->addHours(rand(1, 12)),
                ]);
            }

            $count++;
        }

        $this->command->info("TicketSeeder: {$count} tickets seeded.");
    }
}
