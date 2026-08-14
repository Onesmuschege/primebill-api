<?php

namespace Database\Seeders;

use App\Models\ClientAccount;
use App\Models\DeviceMetric;
use App\Models\NetworkAlert;
use App\Models\NetworkEvent;
use App\Models\NetworkIncident;
use App\Models\NetworkLink;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Traits\SeedsForTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds NOC operational data — device metrics, alerts, topology links,
 * incidents and network events — bound to the tenant's routers. Idempotent
 * via a per-tenant guard on device_metrics.
 */
class NocSeeder extends Seeder
{
    use SeedsForTenant;

    public function run(): void
    {
        $this->forEachTenant(function (Tenant $tenant) {
            $admin = User::where('tenant_id', $tenant->id)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->first();
            $routers = Router::where('tenant_id', $tenant->id)->get();

            if ($routers->isEmpty()) {
                $this->command->warn("NocSeeder [{$tenant->slug}]: No routers found. Skipping.");
                return;
            }

            if (DeviceMetric::where('tenant_id', $tenant->id)->exists()) {
                $this->command->line("  [{$tenant->slug}] NOC metrics already present — skipped.");
                return;
            }

            $created = 0;

            // ── Device metrics ──────────────────────────────────────────────
            $metricTypes = [
                ['type' => 'cpu', 'unit' => '%', 'base' => 30],
                ['type' => 'ram', 'unit' => '%', 'base' => 45],
                ['type' => 'temp', 'unit' => 'C', 'base' => 40],
                ['type' => 'interface_util', 'unit' => '%', 'base' => 55],
                ['type' => 'uptime', 'unit' => 'days', 'base' => 120],
            ];
            foreach ($routers as $rIndex => $router) {
                foreach ($metricTypes as $mIndex => $m) {
                    DeviceMetric::create([
                        'tenant_id' => $tenant->id,
                        'device_id' => $router->id,
                        'metric_type' => $m['type'],
                        'value' => $m['base'] + (($rIndex * 7 + $mIndex * 11) % 40),
                        'interface' => 'ether1',
                        'unit' => $m['unit'],
                        'recorded_at' => Carbon::now()->subMinutes(($rIndex * 5 + $mIndex) * 7),
                    ]);
                    $created++;
                }
            }

            // ── Network alerts (mix of open / acknowledged / resolved) ──────
            $alertTypes = ['device_offline', 'interface_down', 'high_cpu', 'high_util', 'high_latency'];
            foreach ($routers->take(3) as $rIndex => $router) {
                $status = ['open', 'acknowledged', 'resolved'][$rIndex % 3];
                NetworkAlert::create([
                    'tenant_id' => $tenant->id,
                    'device_id' => $router->id,
                    'alert_type' => $alertTypes[$rIndex % count($alertTypes)],
                    'severity' => ['info', 'warning', 'critical'][$rIndex % 3],
                    'message' => 'Seeded alert: ' . $alertTypes[$rIndex % count($alertTypes)] . ' on ' . $router->name,
                    'status' => $status,
                    'metric_value' => 82.5 + $rIndex,
                    'threshold' => 80.0,
                    'interface' => 'ether1',
                    'acknowledged_at' => in_array($status, ['acknowledged', 'resolved']) ? Carbon::now()->subHours(2) : null,
                    'acknowledged_by' => in_array($status, ['acknowledged', 'resolved']) ? $admin?->id : null,
                    'resolved_at' => $status === 'resolved' ? Carbon::now()->subHour() : null,
                    'resolved_by' => $status === 'resolved' ? $admin?->id : null,
                ]);
                $created++;
            }

            // ── Topology links between routers ──────────────────────────────
            for ($i = 0; $i + 1 < $routers->count() && $i < 3; $i++) {
                NetworkLink::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'device_a_id' => $routers[$i]->id, 'device_b_id' => $routers[$i + 1]->id, 'interface_a' => 'sfp1', 'interface_b' => 'sfp1'],
                    [
                        'tenant_id' => $tenant->id,
                        'device_a_id' => $routers[$i]->id,
                        'device_b_id' => $routers[$i + 1]->id,
                        'interface_a' => 'sfp1',
                        'interface_b' => 'sfp1',
                        'media' => 'fiber',
                        'status' => $i === 2 ? 'degraded' : 'up',
                        'description' => 'Core uplink ' . $routers[$i]->name . ' <-> ' . $routers[$i + 1]->name,
                    ]
                );
                $created++;
            }

            // ── Network incidents ───────────────────────────────────────────
            $incidents = [
                ['title' => 'Core switch uplink degradation', 'severity' => 'high', 'status' => 'investigating'],
                ['title' => 'High latency on customer VLAN', 'severity' => 'medium', 'status' => 'resolved'],
                ['title' => 'Power fluctuation at POP', 'severity' => 'critical', 'status' => 'detected'],
                ['title' => 'Firmware restart on edge router', 'severity' => 'low', 'status' => 'closed'],
            ];
            foreach ($incidents as $i => $inc) {
                $router = $routers[$i % $routers->count()];
                $detected = Carbon::now()->subDays((4 - $i) * 2);
                NetworkIncident::updateOrCreate(
                    ['tenant_id' => $tenant->id, 'title' => $inc['title']],
                    [
                        'tenant_id' => $tenant->id,
                        'title' => $inc['title'],
                        'description' => 'Seeded incident: ' . $inc['title'],
                        'severity' => $inc['severity'],
                        'status' => $inc['status'],
                        'detected_at' => $detected,
                        'acknowledged_at' => in_array($inc['status'], ['investigating', 'resolved', 'closed']) ? $detected->copy()->addHour() : null,
                        'resolved_at' => in_array($inc['status'], ['resolved', 'closed']) ? $detected->copy()->addHours(5) : null,
                        'closed_at' => $inc['status'] === 'closed' ? $detected->copy()->addDays(1) : null,
                        'affected_device_id' => $router->id,
                        'created_by' => $admin?->id,
                        'assigned_to' => $admin?->id,
                        'acknowledged_by' => in_array($inc['status'], ['resolved', 'closed']) ? $admin?->id : null,
                        'resolved_by' => in_array($inc['status'], ['resolved', 'closed']) ? $admin?->id : null,
                        'affected_customers_count' => rand(5, 80),
                        'duration_minutes' => in_array($inc['status'], ['resolved', 'closed']) ? rand(120, 700) : null,
                        'root_cause' => in_array($inc['status'], ['resolved', 'closed']) ? 'Faulty transceiver / degraded fiber link' : null,
                        'resolution' => in_array($inc['status'], ['resolved', 'closed']) ? 'Replaced SFP module and re-seated connectors.' : null,
                    ]
                );
                $created++;
            }

            // ── Network events ──────────────────────────────────────────────
            $accounts = ClientAccount::where('tenant_id', $tenant->id)->get();
            $eventTypes = ['SERVICE_STATE_CHANGED', 'AUTH_FAILURE', 'SESSION_STARTED', 'NAS_OFFLINE'];
            for ($i = 0; $i < 8; $i++) {
                $account = $accounts[$i % max(1, $accounts->count())] ?? null;
                $router = $routers[$i % $routers->count()];
                NetworkEvent::create([
                    'tenant_id' => $tenant->id,
                    'event_type' => $eventTypes[$i % count($eventTypes)],
                    'severity' => ['info', 'warning'][$i % 2],
                    'client_id' => $account?->client_id,
                    'client_account_id' => $account?->id,
                    'nas_id' => $router->id,
                    'message' => 'Seeded network event: ' . $eventTypes[$i % count($eventTypes)],
                    'context' => ['seed' => true, 'router' => $router->name],
                    'source' => 'system',
                    'occurred_at' => Carbon::now()->subHours($i * 3),
                ]);
                $created++;
            }

            $this->command->line("  [{$tenant->slug}] {$created} NOC records seeded.");
        });

        $this->command->info('NocSeeder: complete.');
    }
}
