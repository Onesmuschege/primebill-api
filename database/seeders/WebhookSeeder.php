<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds tenant webhooks (with sample delivery history). Idempotent on
 * tenant + code.
 */
class WebhookSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();

            $webhooks = [
                [
                    'code' => 'payment_callback', 'name' => 'Payment Callback',
                    'url' => 'https://billing.' . $tenant->slug . '.example/hooks/payment',
                    'method' => 'POST', 'status' => 'active',
                    'events' => ['payment.completed', 'payment.failed'],
                    'timeout' => 30, 'retry_count' => 3, 'retry_delay' => 60, 'failure_threshold' => 5,
                ],
                [
                    'code' => 'radius_event', 'name' => 'RADIUS Events',
                    'url' => 'https://billing.' . $tenant->slug . '.example/hooks/radius',
                    'method' => 'POST', 'status' => 'active',
                    'events' => ['session.started', 'session.stopped'],
                    'timeout' => 15, 'retry_count' => 3, 'retry_delay' => 30, 'failure_threshold' => 5,
                ],
                [
                    'code' => 'network_alert', 'name' => 'Network Alerts',
                    'url' => 'https://ops.' . $tenant->slug . '.example/hooks/alert',
                    'method' => 'POST', 'status' => 'active',
                    'events' => ['alert.critical', 'incident.created'],
                    'timeout' => 20, 'retry_count' => 4, 'retry_delay' => 45, 'failure_threshold' => 4,
                ],
                [
                    'code' => 'ticket_update', 'name' => 'Support Ticket Updates',
                    'url' => 'https://helpdesk.' . $tenant->slug . '.example/hooks/ticket',
                    'method' => 'PATCH', 'status' => 'paused',
                    'events' => ['ticket.created', 'ticket.updated'],
                    'timeout' => 25, 'retry_count' => 2, 'retry_delay' => 60, 'failure_threshold' => 3,
                    'consecutive_failures' => 3,
                    'last_error' => 'Connection refused',
                ],
            ];

            $created = 0;
            $deliveries = 0;

            foreach ($webhooks as $w) {
                $post = $w['url'] ?? null;
                $webhook = Webhook::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $w['code']],
                    array_merge($w, [
                        'tenant_id' => $tenant->id,
                        'url' => $w['url'],
                        'headers' => ['Content-Type' => 'application/json'],
                        'authentication' => ['type' => 'bearer', 'token' => 'seed-token-' . $tenant->id . '-' . $w['code']],
                        'payload_template' => ['event' => '{{event}}', 'data' => '{{payload}}'],
                        'consecutive_failures' => $w['consecutive_failures'] ?? 0,
                        'last_success_at' => ($w['status'] ?? null) === 'active' ? Carbon::now()->subHour() : null,
                        'last_failure_at' => ($w['status'] ?? null) === 'paused' ? Carbon::now()->subDays(1) : null,
                        'last_error' => $w['last_error'] ?? null,
                        'created_by' => $admin?->id,
                        'updated_by' => $admin?->id,
                    ])
                );

                if ($webhook->wasRecentlyCreated) {
                    $scenarios = [['status' => 'success', 'code' => 200], ['status' => 'failed', 'code' => 500]];
                    foreach ($scenarios as $i => $sc) {
                        WebhookDelivery::create([
                            'tenant_id' => $tenant->id,
                            'webhook_id' => $webhook->id,
                            'event' => $webhook->events[0] ?? 'event.triggered',
                            'status' => $sc['status'],
                            'attempt_number' => $i + 1,
                                                        'request_payload' => json_encode(['event' => 'test', 'data' => ['seed' => true]]),
                            'response_body' => $sc['status'] === 'success' ? '{"ok":true}' : 'Internal Server Error',
                            'response_status' => $sc['code'],
                            'duration_ms' => 120 + $i * 30,
                            'error_message' => $sc['status'] === 'failed' ? 'HTTP 500 response' : null,
                            'next_retry_at' => $sc['status'] === 'failed' ? Carbon::now()->addMinutes($w['retry_delay']) : null,
                            'metadata' => ['seed' => 'delivery-' . $webhook->id . '-' . $i],
                        ]);
                        $deliveries++;
                    }
                }

                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} webhooks and {$deliveries} deliveries seeded.");
        });

        $this->command->info('WebhookSeeder: complete.');
    }
}
