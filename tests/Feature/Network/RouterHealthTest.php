<?php

namespace Tests\Feature\Network;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\MikroTikService;
use App\Services\Network\RouterHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Router health / reachability surfacing (Core ISP Gate — Sections 43/44).
 *
 * A router that is merely CONFIGURED must never appear operational merely
 * because the DB says status = online. CONFIGURED ≠ REACHABLE ≠ SYNCHRONIZED.
 */
class RouterHealthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    protected function tearDown(): void
    {
        Tenant::setCurrent(null);
        parent::tearDown();
    }

    #[Test]
    public function unreachable_router_is_reported_as_unavailable_not_online(): void
    {
        $router = Router::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'status'     => 'online', // DB claims online…
            'ip_address' => '10.99.99.1',
        ]);

        // Real RouterOS probe fails.
        $this->mock(MikroTikService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturn(false);
        });

        $state = app(RouterHealthService::class)->check($router);

        $this->assertFalse($state['reachable']);
        $this->assertFalse($state['provisioning_ready']);
        $this->assertSame(RouterHealthService::UNAVAILABLE, $state['health_state']);
        $this->assertSame('Configured — Unreachable', $state['label']);
        $this->assertNotNull($state['error']);

        // The failed probe is persisted — not silently swallowed.
        $router->refresh();
        $this->assertSame(RouterHealthService::UNAVAILABLE, $router->health_state);
        $this->assertNotNull($router->last_health_check_at);
        $this->assertNotNull($router->last_health_error);
    }

    #[Test]
    public function reachable_router_is_healthy_with_version_and_sync_time(): void
    {
        $router = Router::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'status'     => 'online',
            'ip_address' => '10.99.99.2',
        ]);

        $this->mock(MikroTikService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturn(true);
            $mock->shouldReceive('testConnection')->andReturn(true);
            $mock->shouldReceive('getRouterResources')->andReturn([
                'version'           => '7.14.3',
                'board-name'        => 'hEX S',
                'architecture-name' => 'arm',
            ]);
        });

        $state = app(RouterHealthService::class)->check($router);

        $this->assertTrue($state['reachable']);
        $this->assertTrue($state['synchronized']);
        $this->assertTrue($state['provisioning_ready']);
        $this->assertSame(RouterHealthService::HEALTHY, $state['health_state']);
        $this->assertSame('7.14.3', $state['routeros_version']);

        $router->refresh();
        $this->assertSame(RouterHealthService::HEALTHY, $router->health_state);
        $this->assertNotNull($router->last_sync_at);
        $this->assertNull($router->last_health_error);
    }

    #[Test]
    public function cache_only_summary_never_claims_reachable_without_fresh_probe(): void
    {
        $router = Router::factory()->create([
            'tenant_id'            => $this->tenant->id,
            'ip_address'           => '10.99.99.3',
            // Simulate a probe result from over an hour ago.
            'health_state'         => RouterHealthService::HEALTHY,
            'last_health_check_at' => now()->subHour(),
        ]);

        $state = app(RouterHealthService::class)->summarize($router);

        // Stale evidence — must NOT be reported as reachable.
        $this->assertFalse($state['reachable']);
        $this->assertFalse($state['provisioning_ready']);
    }

    #[Test]
    public function health_sweep_isolates_one_failing_router(): void
    {
        Router::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'ip_address' => '10.99.99.4',
        ]);
        Router::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'ip_address' => '10.99.99.5',
        ]);

        // First router unreachable, second healthy — the sweep must still
        // complete and report both (Section 66 per-record isolation).
        $this->mock(MikroTikService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturn(false, true);
            $mock->shouldReceive('testConnection')->andReturn(true);
            $mock->shouldReceive('getRouterResources')->andReturn(['version' => '7.15']);
        });

        $summary = app(RouterHealthService::class)->checkAll($this->tenant->id);

        $this->assertSame(2, $summary['checked']);
        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(1, $summary['unavailable']);
    }
}
