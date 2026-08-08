<?php

namespace Tests\Feature;

use App\Models\DeviceMetric;
use App\Models\NetworkAlert;
use App\Models\NetworkLink;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\AlertService;
use App\Services\Network\MonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Network Operations Center feature tests.
 *
 * Covers metric recording, alert threshold firing, alert lifecycle
 * (acknowledge/resolve), topology links, and tenant isolation.
 */
class NocTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    protected function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    protected function createDevice(array $overrides = []): Router
    {
        return Router::factory()->create(array_merge([
            'tenant_id'   => $this->tenant->id,
            'device_type' => 'router',
            'status'      => 'online',
        ], $overrides));
    }

    // ─── Overview ─────────────────────────────────────────────────────────

    public function test_overview_returns_kpis(): void
    {
        $this->createDevice(['status' => 'online']);
        $this->createDevice(['status' => 'offline']);

        NetworkAlert::factory()->create([
            'tenant_id' => $this->tenant->id,
            'severity'  => 'critical',
            'status'    => 'open',
        ]);

        $response = $this->getJson('/api/noc/overview', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.total_devices', 2)
                 ->assertJsonPath('data.online_devices', 1)
                 ->assertJsonPath('data.offline_devices', 1)
                 ->assertJsonPath('data.open_alerts', 1)
                 ->assertJsonPath('data.critical_alerts', 1);
    }

    // ─── Devices ──────────────────────────────────────────────────────────

    public function test_can_list_devices(): void
    {
        $this->createDevice(['name' => 'Core Router', 'device_type' => 'router']);
        $this->createDevice(['name' => 'Edge Switch', 'device_type' => 'switch']);

        $response = $this->getJson('/api/noc/devices', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(2, 'data.data');
    }

    public function test_can_filter_devices_by_type(): void
    {
        $this->createDevice(['name' => 'Core Router', 'device_type' => 'router']);
        $this->createDevice(['name' => 'Edge Switch', 'device_type' => 'switch']);

        $response = $this->getJson('/api/noc/devices?device_type=switch', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data.data')
                 ->assertJsonPath('data.data.0.device_type', 'switch');
    }

    // ─── Metrics ──────────────────────────────────────────────────────────

    public function test_metric_recording_via_service(): void
    {
        $device = $this->createDevice();
        $monitor = app(MonitorService::class);

        $metric = $monitor->record($device, 'cpu', 45.5, null, '%');

        $this->assertDatabaseHas('device_metrics', [
            'id'          => $metric->id,
            'device_id'   => $device->id,
            'metric_type' => 'cpu',
            'value'       => 45.5,
        ]);
    }

    public function test_metric_endpoint_returns_paginated_metrics(): void
    {
        $device = $this->createDevice();

        DeviceMetric::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'metric_type' => 'cpu',
        ]);

        $response = $this->getJson("/api/noc/devices/{$device->id}/metrics", $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(3, 'data.data');
    }

    // ─── Alerts ───────────────────────────────────────────────────────────

    public function test_alert_threshold_fires(): void
    {
        $device = $this->createDevice();
        $monitor = app(MonitorService::class);

        // CPU threshold is 85; a value of 95 must raise a high_cpu alert.
        $monitor->record($device, 'cpu', 95, null, '%');

        $this->assertDatabaseHas('network_alerts', [
            'device_id'  => $device->id,
            'alert_type' => 'high_cpu',
            'status'     => 'open',
        ]);
    }

    public function test_alert_does_not_fire_below_threshold(): void
    {
        $device = $this->createDevice();
        $monitor = app(MonitorService::class);

        $monitor->record($device, 'cpu', 30, null, '%');

        $this->assertDatabaseMissing('network_alerts', [
            'device_id'  => $device->id,
            'alert_type' => 'high_cpu',
        ]);
    }

    public function test_alert_auto_resolves_when_back_below_threshold(): void
    {
        $device = $this->createDevice();
        $monitor = app(MonitorService::class);

        $monitor->record($device, 'cpu', 95, null, '%');
        $this->assertDatabaseHas('network_alerts', [
            'device_id'  => $device->id,
            'alert_type' => 'high_cpu',
            'status'     => 'open',
        ]);

        // Now below threshold — the open alert should auto-resolve.
        $monitor->record($device, 'cpu', 30, null, '%');

        $this->assertDatabaseHas('network_alerts', [
            'device_id'  => $device->id,
            'alert_type' => 'high_cpu',
            'status'     => 'resolved',
        ]);
    }

    public function test_can_list_alerts(): void
    {
        NetworkAlert::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'open',
        ]);

        $response = $this->getJson('/api/noc/alerts', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(2, 'data.data');
    }

    public function test_can_acknowledge_alert(): void
    {
        $device = $this->createDevice();
        $alert = NetworkAlert::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'status'    => 'open',
        ]);

        $response = $this->postJson("/api/noc/alerts/{$alert->id}/acknowledge", [], $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseHas('network_alerts', [
            'id'              => $alert->id,
            'status'          => 'acknowledged',
            'acknowledged_by' => $this->user->id,
        ]);
    }

    public function test_can_resolve_alert(): void
    {
        $device = $this->createDevice();
        $alert = NetworkAlert::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'status'    => 'acknowledged',
        ]);

        $response = $this->postJson("/api/noc/alerts/{$alert->id}/resolve", [], $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseHas('network_alerts', [
            'id'          => $alert->id,
            'status'      => 'resolved',
            'resolved_by' => $this->user->id,
        ]);
    }

    // ─── Links / Topology ─────────────────────────────────────────────────

    public function test_can_create_link(): void
    {
        $a = $this->createDevice(['name' => 'Router A']);
        $b = $this->createDevice(['name' => 'Router B']);

        $response = $this->postJson('/api/noc/links', [
            'device_a_id' => $a->id,
            'device_b_id' => $b->id,
            'interface_a' => 'ether1',
            'interface_b' => 'ether1',
            'media'       => 'fiber',
            'status'      => 'up',
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.media', 'fiber');
    }

    public function test_can_list_links(): void
    {
        $a = $this->createDevice(['name' => 'Router A']);
        $b = $this->createDevice(['name' => 'Router B']);

        NetworkLink::factory()->create([
            'tenant_id'   => $this->tenant->id,
            'device_a_id' => $a->id,
            'device_b_id' => $b->id,
            'status'      => 'up',
        ]);

        $response = $this->getJson('/api/noc/links', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(1, 'data.data');
    }

    // ─── Tenant Isolation ─────────────────────────────────────────────────

    public function test_tenant_isolation_for_alerts(): void
    {
        $device = $this->createDevice();
        NetworkAlert::factory()->create([
            'tenant_id' => $this->tenant->id,
            'device_id' => $device->id,
            'status'    => 'open',
        ]);

        $otherTenant = Tenant::factory()->create();
        Tenant::setCurrent($otherTenant);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser->assignRole('super_admin');
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        $response = $this->getJson('/api/noc/alerts', [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonCount(0, 'data.data');
    }

    public function test_tenant_isolation_for_devices(): void
    {
        $this->createDevice(['name' => 'Tenant A Router']);

        $otherTenant = Tenant::factory()->create();
        Tenant::setCurrent($otherTenant);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser->assignRole('super_admin');
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        $response = $this->getJson('/api/noc/devices', [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonCount(0, 'data.data');
    }
}
