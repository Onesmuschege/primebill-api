<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\CustomerFeedback;
use App\Models\CustomerInteraction;
use App\Models\CustomerJourneyEvent;
use App\Models\CustomerSatisfaction;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds customer-experience data: interactions, journey events, feedback
 * and satisfaction scores against real clients. Idempotent guard.
 */
class CustomerExperienceSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            $clients = Client::where('tenant_id', $tenant->id)->where('status', 'active')->get();

            if ($clients->isEmpty()) {
                $this->command->warn("CustomerExperienceSeeder [{$tenant->slug}]: No active clients found. Skipping.");
                return;
            }

            if (CustomerInteraction::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Customer experience already present — skipped.");
                return;
            }

            $created = 0;
            foreach ($clients->take(15) as $i => $client) {
                // Interaction
                CustomerInteraction::create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'type' => ['call', 'visit', 'email', 'ticket'][$i % 4],
                    'direction' => $i % 2 === 0 ? 'inbound' : 'outbound',
                    'status' => ['completed', 'pending', 'follow_up'][$i % 3],
                    'subject' => 'Customer care interaction',
                    'summary' => 'Seeded interaction covering account feedback.',
                    'notes' => 'Customer requested information about plan options.',
                    'attachments' => null,
                    'metadata' => ['seed' => 'cx-interaction-' . $i],
                    'user_id' => $admin?->id,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Seeder/1.0',
                    'created_at' => Carbon::now()->subDays($i + 1),
                    'updated_at' => Carbon::now()->subDays($i + 1),
                ]);
                $created++;

                // Journey event
                CustomerJourneyEvent::create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'event' => ['signed_up', 'first_payment', 'ticket_created', 'maintenance_notified'][$i % 4],
                    'category' => ['onboarding', 'billing', 'support', 'communication'][$i % 4],
                    'description' => 'Seeded customer journey milestone.',
                    'metadata' => ['seed' => 'cx-journey-' . $i],
                    'user_id' => $admin?->id,
                    'created_at' => Carbon::now()->subDays($i + 2),
                ]);
                $created++;

                // Feedback
                CustomerFeedback::create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'type' => ['complaint', 'suggestion', 'praise', 'survey'][$i % 4],
                    'category' => ['service', 'billing', 'network'][$i % 3],
                    'rating' => ($i % 5) + 1,
                    'subject' => 'Customer feedback',
                    'feedback' => 'Seeded feedback about overall service quality.',
                    'responses' => ['q1' => 'satisfied'],
                    'attachments' => null,
                    'status' => ['new', 'resolved', 'in_review'][$i % 3],
                    'response' => $i % 3 === 1 ? 'Thank you for your feedback.' : null,
                    'responded_by' => $i % 3 === 1 ? $admin?->id : null,
                    'responded_at' => $i % 3 === 1 ? Carbon::now()->subDays($i) : null,
                    'metadata' => ['seed' => 'cx-feedback-' . $i],
                    'created_by' => $admin?->id,
                    'created_at' => Carbon::now()->subDays($i + 3),
                    'updated_at' => Carbon::now()->subDays($i + 3),
                ]);
                $created++;

                // Satisfaction
                CustomerSatisfaction::create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'type' => 'csat',
                    'source' => 'email_survey',
                    'score' => 3 + ($i % 5), // 3..7 of 7
                    'max_score' => 7,
                    'category' => ['overall', 'support', 'network'][$i % 3],
                    'comment' => 'Seeded satisfaction score.',
                    'responses' => ['speed' => 4, 'support' => 5],
                    'metadata' => ['seed' => 'cx-csat-' . $i],
                    'created_by' => $admin?->id,
                    'created_at' => Carbon::now()->subDays($i + 4),
                    'updated_at' => Carbon::now()->subDays($i + 4),
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} customer experience records seeded.");
        });

        $this->command->info('CustomerExperienceSeeder: complete.');
    }
}
