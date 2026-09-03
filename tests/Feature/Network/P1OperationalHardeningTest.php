<?php

namespace Tests\Feature\Network;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\FupLog;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\EffectiveRateResolver;
use App\Services\Network\MikroTikService;
use App\Services\Network\ProvisioningService;
use App\Services\Network\RouterHealthService;
use App\Services\Radius\MockRadiusAdapter;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1 — router health/reachability surfacing + FUP-vs-reconciliation hardening.
 *
 * Sections 23 (FUP must not be overwritten by generic reconciliation),
 * 43 (a DB status=online row must not imply reachability) and 44
 * (CONFIGURED vs REACHABLE vs SYNCHRONIZED).
 */
class P1OperationalHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->client = Client::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::setCurrent(null);
        parent::tearDown();
    }
// ─── Router health: CONFIGURED ≠ REACHABLE (Sections 43/44) ──────────

    #[Test]
    public function never_probed_router_is_unknown_not_healthy(): void
    {
        $router = Router::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'online', // DB says online — not proof of reachability
        ]);

        $summary = app(RouterHealthService::class)->summarize($router);

        $this->assertSame('Configured — Never Probed', $summary['label']);
        // A router that was never probed must not claim reachability.
        $this->assertFalse($summary['reachable']);
        $this->assertFalse($summary['synchronized']);
        $this->assertSame(RouterHealthService::UNKNOWN, $summary['health_state']);
    }

    #[Test]
    public function live_probe_persists_healthy_state_and_reachability(): void
    {
        $router = Router::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'online',
            'last_sync_at' => null,
        ]);

        // Swap the MikroTik service for a spy that always "connects".
        $mikrotikSpy = new class extends MikroTikService
        {
            public function connect(Router $router): bool
            {
                return true;
            }

            public function testConnection(): bool
            {
                return true;
            }

            public function getRouterResources(): array
            {
                return ['version' => '7.15.2'];
            }
        };

        $this->app->instance(MikroTikService::class, $mikrotikSpy);

        $result = app(RouterHealthService::class)->check($router);

        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['synchronized']);
        $this->assertSame(RouterHealthService::HEALTHY, $result['health_state']);
        $this->assertSame('7.15.2', $result['routeros_version']);

        // Persisted so a cached summary agrees with the probe.
        $router->refresh();
        $this->assertSame(RouterHealthService::HEALTHY, $router->health_state);
        $this->assertNotNull($router->last_health_check_at);
        $this->assertNotNull($router->last_sync_at);
    }

    #[Test]
    public function failed_probe_marks_router_unavailable(): void
    {
        $router = Router::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'online',
        ]);

        $mikrotikSpy = new class extends MikroTikService
        {
            public function connect(Router $router): bool
            {
                return false;
            }

            public function testConnection(): bool
            {
                return false;
            }
        };

        $this->app->instance(MikroTikService::class, $mikrotikSpy);

        $result = app(RouterHealthService::class)->check($router);

        $this->assertFalse($result['reachable']);
        $this->assertSame(RouterHealthService::UNAVAILABLE, $result['health_state']);
        $this->assertSame('Configured — Unreachable', $result['label']);

        $router->refresh();
        $this->assertSame(RouterHealthService::UNAVAILABLE, $router->health_state);
        $this->assertNotNull($router->last_health_error);
        $this->assertNotNull($router->last_health_check_at);
    }
#[Test]
    public function stale_healthy_router_is_reported_degraded(): void
    {
        $router = Router::factory()->create([
            'tenant_id'            => $this->tenant->id,
            'status'               => 'online',
            'health_state'         => RouterHealthService::HEALTHY,
            'last_health_check_at' => now()->subMinutes(90), // older than 15-min stale window
            'last_sync_at'         => now()->subMinutes(90),
        ]);

        $state = app(RouterHealthService::class)->reportedState($router);

        $this->assertSame(RouterHealthService::DEGRADED, $state);
    }

    #[Test]
    public function check_all_sweeps_with_tenant_scope(): void
    {
        Router::factory()->create(['tenant_id' => $this->tenant->id]);

        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        Router::factory()->create(['tenant_id' => $otherTenant->id]);

        // Only tenant-scoped routers are touched.
        $summary = app(RouterHealthService::class)->checkAll($this->tenant->id);

        $this->assertSame(1, $summary['checked']);
        $this->assertCount(1, $summary['results']);
    }

    // ─── FUP vs generic reconciliation (Section 23) ──────────────────────

    protected function makeFupPlan(): Plan
    {
        return Plan::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'name'           => 'FupHardening',
            'speed_up'       => 5120,
            'speed_down'     => 10240,
            'fup_limit'      => 1,
            'fup_speed_up'   => 512,
            'fup_speed_down' => 1024,
        ]);
    }

    protected function makeAccount(Plan $plan, array $overrides = []): ClientAccount
    {
        return ClientAccount::factory()->create(array_merge([
            'tenant_id'         => $this->tenant->id,
            'client_id'         => $this->client->id,
            'plan_id'           => $plan->id,
            'username'          => 'hardening01',
            'password'          => bcrypt('secret'),
            'type'              => 'prepaid',
            'status'            => 'active',
            'service_state'     => ClientAccount::STATE_ACTIVE,
            'rate_limit_policy' => '512k/1024k', // FUP wrote the throttle
        ], $overrides));
    }
#[Test]
    public function effective_rate_resolver_returns_fup_rate_while_override_active(): void
    {
        $plan = $this->makeFupPlan();
        $account = $this->makeAccount($plan);

        // FUP triggered in the past.
        FupLog::create([
            'client_account_id' => $account->id,
            'bytes_used'        => 2 * 1024 * 1024,
            'triggered_at'      => now()->subDay(),
        ]);

        $resolver = app(EffectiveRateResolver::class);

        // Throttled while FUP wins, base when not.
        $this->assertSame('512k/1024k', $resolver->effectiveRate($account));
        $this->assertTrue($resolver->isFupActive($account));

        // Remove the trigger → base plan rate.
        FupLog::where('client_account_id', $account->id)->update(['triggered_at' => null]);

        $this->assertFalse($resolver->isFupActive($account->fresh()));
        $this->assertSame('5120k/10240k', $resolver->effectiveRate($account->fresh()));
    }

    #[Test]
    public function generic_reprovisioning_does_not_overwrite_fup_throttle(): void
    {
        $plan = $this->makeFupPlan();
        $account = $this->makeAccount($plan);

        FupLog::create([
            'client_account_id' => $account->id,
            'bytes_used'        => 2 * 1024 * 1024,
            'triggered_at'      => now()->subDay(),
        ]);

        // Spy RADIUS adapter records the rate_limit it is asked to write.
        $radiusSpy = new class extends MockRadiusAdapter
        {
            public array $createdRates = [];

            public function createUser(array $data): bool
            {
                $this->createdRates[] = $data['rate_limit'] ?? null;

                return true;
            }
        };
        $this->app->instance(RadiusAdapterInterface::class, $radiusSpy);

        // Router adapter swallow (default mock already returns true).
        $this->app->instance(
            \App\Services\Network\RouterAdapterInterface::class,
            new class implements \App\Services\Network\RouterAdapterInterface
            {
                public function createUser(array $data): bool { return true; }
                public function deleteUser(string $username): bool { return true; }
                public function suspendUser(string $username): bool { return true; }
                public function unsuspendUser(string $username): bool { return true; }
                public function disconnectSession(string $username, ?string $sessionId = null): bool { return true; }
                public function testConnection(): bool { return true; }
            }
        );

        // This mimics `radius:sync-users` / a generic re-provision job.
        $ok = app(ProvisioningService::class)->provisionAccount($account, 'secret');

        $this->assertTrue($ok);

        // The written rate must be the FUP-throttled rate, NOT the base 5120k/10240k.
        $this->assertCount(1, $radiusSpy->createdRates);
        $this->assertSame('512k/1024k', $radiusSpy->createdRates[0]);

        // Structured provisioning log proves the same.
        $this->assertDatabaseHas('mikrotik_sync_logs', [
            'tenant_id'         => $this->tenant->id,
            'client_account_id' => $account->id,
            'operation'         => 'provision',
            'status'            => 'success',
        ]);
    }
}