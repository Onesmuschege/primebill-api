<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\CommunicationLog;
use App\Models\CommunicationTemplate;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds communication logs referencing real templates and recipients
 * (clients + users). Idempotent via a dedupe guard on the provider
 * reference which is deterministic per tenant.
 */
class CommunicationLogSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $templates = CommunicationTemplate::where('tenant_id', $tenant->id)->get();
            $clients = Client::where('tenant_id', $tenant->id)->where('status', 'active')->get();
            $users = User::where('tenant_id', $tenant->id)->get();
            $admin = $users
                ->filter(fn ($u) => $u->roles->contains('name', 'super_admin'))
                ->first() ?? $users->first();

            if ($templates->isEmpty() || $clients->isEmpty()) {
                $this->command->warn("CommunicationLogSeeder [{$tenant->slug}]: No templates/clients found. Skipping.");
                return;
            }

            if (CommunicationLog::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] Communication logs already present — skipped.");
                return;
            }

            $created = 0;
            $billTemplates = $templates->whereIn('code', ['invoice', 'payment_receipt', 'suspension'])->values();
            $supportTemplates = $templates->whereIn('code', ['outage', 'maintenance', 'ticket_update', 'welcome'])->values();

            foreach ($clients->take(25) as $index => $client) {
                $template = (($index % 2) === 0)
                    ? ($billTemplates[$index % max(1, $billTemplates->count())] ?? null)
                    : ($supportTemplates[$index % max(1, $supportTemplates->count())] ?? null);
                if (! $template) {
                    $template = $templates->first();
                }

                $scenario = [
                    ['status' => 'delivered', 'sent' => 5, 'delivered' => 4, 'opened' => 3],
                    ['status' => 'sent', 'sent' => 2, 'delivered' => null, 'opened' => null],
                    ['status' => 'failed', 'sent' => 1, 'delivered' => null, 'opened' => null],
                    ['status' => 'pending', 'sent' => null, 'delivered' => null, 'opened' => null],
                ][$index % 4];

                $sentAt = Carbon::now()->subDays(($index + 1) * 2)->setTime(10, 30);
                $recipientType = ($index % 5 === 4) ? 'user' : 'client';

                CommunicationLog::create([
                    'tenant_id' => $tenant->id,
                    'communication_template_id' => $template->id,
                    'channel' => $template->type,
                    'recipient_type' => $recipientType,
                    'recipient_id' => $recipientType === 'client' ? $client->id : ($admin?->id ?? $client->id),
                    'recipient_address' => $recipientType === 'client' ? $client->email : ($admin?->email ?? $client->email),
                    'status' => $scenario['status'],
                    'subject' => $template->subject,
                    'content' => $template->content,
                    'provider' => $template->type === 'sms' ? 'africas_talking' : 'smtp',
                    'provider_reference' => 'COMM-' . $tenant->id . '-' . ($index + 1),
                    'error_message' => $scenario['status'] === 'failed' ? 'Provider timeout: gateway unavailable' : null,
                    'retry_count' => $scenario['status'] === 'failed' ? 2 : 0,
                    'sent_at' => $scenario['sent'] ? $sentAt : null,
                    'delivered_at' => $scenario['delivered'] ? $sentAt->copy()->addMinutes(3) : null,
                    'opened_at' => $scenario['opened'] ? $sentAt->copy()->addMinutes(40) : null,
                    'clicked_at' => $scenario['opened'] ? $sentAt->copy()->addMinutes(52) : null,
                    'metadata' => ['seed' => 'comm-log-' . $tenant->id . '-' . $client->id . '-' . $index],
                    'created_by' => $admin?->id,
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} communication logs seeded.");
        });

        $this->command->info('CommunicationLogSeeder: complete.');
    }
}
