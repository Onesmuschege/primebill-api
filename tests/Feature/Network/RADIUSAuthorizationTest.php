<?php

namespace Tests\Feature\Network;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Services\Radius\RadiusAdapterInterface;

class RADIUSAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private string $token;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test')->plainTextToken;

        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
    }

    /** @test */
    public function radius_adapter_generates_correct_bandwidth_policy(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Plan-30Mbps',
            'speed_up' => 3072,   // 3 Mbps
            'speed_down' => 30720, // 30 Mbps
        ]);

        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $plan->id,
            'username' => 'radiusbw01',
            'password' => bcrypt('secret'),
            'type' => 'prepaid',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);

        $adapter = new \App\Services\Radius\FreeRadiusAdapter();

        // Use reflection to test the protected buildRateLimit method via public syncUsersToAccount
        // or test the behavior through ProvisioningService
        $reflection = new \ReflectionClass($adapter);
        $method = $reflection->getMethod('buildRateLimit');
        $method->setAccessible(true);

        $rateLimit = $method->invoke($adapter, $plan);

        $this->assertEquals('3072k/30720k', $rateLimit);
    }

    /** @test */
    public function radius_adapter_handles_zero_speeds_with_defaults(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Plan-Default',
            'speed_up' => 0,
            'speed_down' => 0,
        ]);

        $adapter = new \App\Services\Radius\FreeRadiusAdapter();
        $reflection = new \ReflectionClass($adapter);
        $method = $reflection->getMethod('buildRateLimit');
        $method->setAccessible(true);

        $rateLimit = $method->invoke($adapter, $plan);

        // Should fall back to defaults: 512k/1024k
        $this->assertEquals('512k/1024k', $rateLimit);
    }

    /** @test */
    public function provisioning_service_syncs_radius_user(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'SyncPlan',
            'speed_up' => 1024,
            'speed_down' => 10240,
        ]);

        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $plan->id,
            'username' => 'syncuser01',
            'password' => 'plainpass',
            'type' => 'pppoe',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);

        $radiusAdapter = new class extends \App\Services\Radius\MockRadiusAdapter {
            public bool $createUserCalled = false;
            public array $lastCreateUserData = [];

            public function createUser(array $data): bool
            {
                $this->createUserCalled = true;
                $this->lastCreateUserData = $data;
                return true;
            }

            public function deleteUser(string $username): bool
            {
                return true;
            }

            public function suspendUser(string $username): bool
            {
                return true;
            }

            public function unsuspendUser(string $username): bool
            {
                return true;
            }

            public function syncUsers(): bool
            {
                return true;
            }

            public function syncUsersToAccount(\App\Models\ClientAccount $account): bool
            {
                return true;
            }
        };

        $routerAdapter = new class extends \App\Services\Network\MockRouterAdapter {
            public bool $createUserCalled = false;

            public function createUser(array $data): bool
            {
                $this->createUserCalled = true;
                return true;
            }

            public function deleteUser(string $username): bool
            {
                return true;
            }

            public function suspendUser(string $username): bool
            {
                return true;
            }

            public function unsuspendUser(string $username): bool
            {
                return true;
            }
        };

        $provisioning = new \App\Services\Network\ProvisioningService($routerAdapter, $radiusAdapter);
        $result = $provisioning->provisionAccount($account, 'plainpass');

        $this->assertTrue($result);
        $this->assertTrue($radiusAdapter->createUserCalled);
        $this->assertEquals('syncuser01', $radiusAdapter->lastCreateUserData['username']);
        $this->assertEquals('SyncPlan', $radiusAdapter->lastCreateUserData['group']);
        $this->assertEquals('1024k/10240k', $radiusAdapter->lastCreateUserData['rate_limit']);
    }

    /** @test */
    public function provisioning_service_handles_mikrotik_failure_gracefully(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'FailPlan',
        ]);

        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $plan->id,
            'username' => 'failuser01',
            'password' => 'plainpass',
            'type' => 'pppoe',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);

        $radiusAdapter = new class extends \App\Services\Radius\MockRadiusAdapter {
            public bool $createUserCalled = false;

            public function createUser(array $data): bool
            {
                $this->createUserCalled = true;
                return true;
            }

            public function deleteUser(string $username): bool
            {
                return true;
            }

            public function suspendUser(string $username): bool
            {
                return true;
            }

            public function unsuspendUser(string $username): bool
            {
                return true;
            }

            public function syncUsers(): bool
            {
                return true;
            }

            public function syncUsersToAccount(\App\Models\ClientAccount $account): bool
            {
                return true;
            }
        };

        $routerAdapter = new class extends \App\Services\Network\MockRouterAdapter {
            public function createUser(array $data): bool
            {
                return false; // MikroTik offline
            }

            public function deleteUser(string $username): bool
            {
                return false;
            }

            public function suspendUser(string $username): bool
            {
                return false;
            }

            public function unsuspendUser(string $username): bool
            {
                return false;
            }
        };

        $provisioning = new \App\Services\Network\ProvisioningService($routerAdapter, $radiusAdapter);
        $result = $provisioning->provisionAccount($account, 'plainpass');

        // Partial failure: Radius OK, Router failed
        $this->assertFalse($result);
        $this->assertTrue($radiusAdapter->createUserCalled);
    }
}
