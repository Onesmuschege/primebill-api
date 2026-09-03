<?php

namespace Tests\Feature\Network;

use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\RouterAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class P2OperationalSurfaceTest extends TestCase
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
    public function system_health_endpoint_returns_structured_substates(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/system/health');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => ['overall', 'database', 'queue', 'scheduler', 'radius', 'routers', 'provisioning', 'mpesa'],
        ]);
        $response->assertJsonPath('data.overall', 'healthy');
        $response->assertJsonPath('data.radius.state', 'degraded'); // mock driver degrades
        $response->assertJsonPath('data.routers.total', 0);         // no routers configured
    }
    #[Test]
    public function system_health_flags_a_configured_router_as_unknown_until_probed(): void
    {
        Sanctum::actingAs($this->user);

        Router::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'edge-1',
            'type' => 'mikrotik',
        ]);

        $response = $this->getJson('/api/system/health');
        $response->assertStatus(200);
        $response->assertJsonPath('data.routers.state', 'degraded');
        $response->assertJsonPath('data.routers.counts.unknown', 1);
    }

    #[Test]
    public function bulk_suspend_routes_each_account_through_the_authority(): void
    {
        $spy = $this->bindCountingRouterAdapter();

        $plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'username' => 'bulk-suspend-1',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);

        $response = $this->postJson('/api/network/services/bulk/suspend', [
            'account_ids' => [$account->id],
            'reason' => 'bulk test',
        ], ['Authorization' => 'Bearer ' . $this->token]);

        $response->assertStatus(200);
        $response->assertJsonPath('succeeded', 1);
        $response->assertJsonPath('failed', 0);

        $account->refresh();
        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);
        $this->assertSame(ClientAccount::SUSPENSION_ADMIN, $account->suspension_type);
        $this->assertSame(1, $spy->suspendCalls, 'bulk suspend must invoke the router adapter');
    }

    protected function bindCountingRouterAdapter(): object
    {
        $spy = new class implements RouterAdapterInterface {
            public int $suspendCalls = 0;
            public int $unsuspendCalls = 0;

            public function createUser(array $data): bool { return true; }
            public function deleteUser(string $username): bool { return true; }
            public function suspendUser(string $username): bool { $this->suspendCalls++; return true; }
            public function unsuspendUser(string $username): bool { $this->unsuspendCalls++; return true; }
            public function disconnectSession(string $username): bool { return true; }
            public function testConnection(): bool { return true; }
        };

        $this->app->instance(RouterAdapterInterface::class, $spy);

        return $spy;
    }
}