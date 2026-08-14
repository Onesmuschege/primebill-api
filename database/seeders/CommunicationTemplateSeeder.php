<?php

namespace Database\Seeders;

use App\Models\CommunicationTemplate;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;

/**
 * Seeds communication templates per tenant (invoice, receipt, suspension,
 * restoration, outage, maintenance, ticket_update, welcome). Idempotent.
 */
class CommunicationTemplateSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $templates = [
                ['code' => 'invoice', 'name' => 'Invoice Ready', 'type' => 'email', 'category' => 'billing', 'priority' => 'normal', 'subject' => 'Invoice {invoice_number} is ready ({amount})', 'content' => 'Dear {name},\n\nYour invoice {invoice_number} of {amount} is now available and due on {due_date}. Please make payment to keep your service active.\n\nThank you.' ],
                ['code' => 'payment_receipt', 'name' => 'Payment Receipt', 'type' => 'email', 'category' => 'billing', 'priority' => 'normal', 'subject' => 'Payment received — {amount}', 'content' => 'Dear {name},\n\nWe have received your payment of {amount} (ref: {reference}). Thank you for your prompt payment.' ],
                ['code' => 'suspension', 'name' => 'Service Suspension Notice', 'type' => 'email', 'category' => 'billing', 'priority' => 'high', 'subject' => 'Important: service suspension', 'content' => 'Dear {name},\n\nYour service will be suspended on {date} due to non-payment of invoice {invoice_number}.' ],
                ['code' => 'restoration', 'name' => 'Service Restored', 'type' => 'sms', 'category' => 'billing', 'priority' => 'high', 'subject' => null, 'content' => 'Dear {name}, your service has been restored. Thank you.' ],
                ['code' => 'outage', 'name' => 'Outage Alert', 'type' => 'email', 'category' => 'support', 'priority' => 'high', 'subject' => 'Network outage in your area', 'content' => 'Dear {name},\n\nWe are aware of an outage affecting your area and our engineers are working to restore service.' ],
                ['code' => 'maintenance', 'name' => 'Planned Maintenance', 'type' => 'email', 'category' => 'maintenance', 'priority' => 'normal', 'subject' => 'Planned maintenance {date}', 'content' => 'Dear {name},\n\nThere will be scheduled maintenance on {start} — {end}. You may experience brief interruptions.' ],
                ['code' => 'ticket_update', 'name' => 'Support Ticket Update', 'type' => 'email', 'category' => 'support', 'priority' => 'normal', 'subject' => 'Update on ticket {ticket_number}', 'content' => 'Dear {name},\n\nThere is an update on your support ticket: {message}' ],
                ['code' => 'welcome', 'name' => 'Welcome to {brand}', 'type' => 'sms', 'category' => 'system', 'priority' => 'normal', 'subject' => null, 'content' => 'Welcome {name}! Your {plan} connection is active. Username: {username}' ],
            ];

            $created = 0;
            foreach ($templates as $t) {
                CommunicationTemplate::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $t['code']],
                    array_merge($t, [
                        'tenant_id' => $tenant->id,
                        'content' => $t['content'],
                        'content_plain' => str_replace("\n", "\n", $t['content']),
                        'variables' => ['{name}', '{invoice_number}', '{amount}', '{due_date}', '{date}', '{message}', '{username}', '{plan}', '{brand}'],
                        'is_active' => true,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} communication templates seeded.");
        });

        $this->command->info('CommunicationTemplateSeeder: complete.');
    }
}
