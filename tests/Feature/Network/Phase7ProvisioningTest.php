<?php

namespace Tests\Feature\Network;

use App\Events\ProvisioningFailed;
use App\Events\ProvisioningSucceeded;
use App\Jobs\ProvisionClientAccountJob;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\MikrotikSyncLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\ProvisioningService;
use App\Services\Network\RouterAdapterInterface;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Phase7ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->client = Client::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'active',
        ]);
        $this->plan = Plan::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Starter Fibre',
            'type'      => 'pppoe',
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::setCurrent(null);
        parent::tearDown();
    }

    private function makeAccount(string $username, string $state = ClientAccount::STATE_PENDING): ClientAccount
    {
        return ClientAccount::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'client_id'     => $this->client->id,
            'plan_id'       => $this->plan->id,
            'username'      => $username,
            'service_state' => $state,
            'access_method' => ClientAccount::ACCESS_PPPOE,
        ]);
    }

    #[Test]
    public function provision_success_dispatches_succeeded_event_and_audits_key(): void
    {
        Event::fake([ProvisioningSucceeded::class, ProvisioningFailed::class]);

        $account = $this->makeAccount('evt-success');

        $ok = app(ProvisioningService::class)->provisionAccount($account, 'plainsecret');

        $this->assertTrue($ok);
        Event::assertDispatched(ProvisioningSucceeded::class, function ($event) use ($account) {
            return $event->entity instanceof ClientAccount
                && $event->entity->id === $account->id
                && $event->context['operation'] === 'provision'
                && $event->context['idempotency_key'] === "provision:{$account->id}";
        });
        Event::assertNotDispatched(ProvisioningFailed::class);

        $this->assertDatabaseHas('mikrotik_sync_logs', [
            'client_account_id' => $account->id,
            'operation'         => 'provision',
            'status'            => 'success',
            'idempotency_key'   => "provision:{$account->id}",
        ]);
    }

    #[Test]
    public function provision_failure_dispatches_failed_event_and_writes_failure_audit(): void
    {
        Event::fake([ProvisioningSucceeded::class, ProvisioningFailed::class]);

        $this->app->instance(RouterAdapterInterface::class, $this->failingRouter());
        $this->app->instance(RadiusAdapterInterface::class, $this->failingRadius());

        $account = $this->makeAccount('evt-fail');

        $ok = app(ProvisioningService::class)->provisionAccount($account, 'plainsecret');

        $this->assertFalse($ok);
        Event::assertDispatched(ProvisioningFailed::class, function ($event) use ($account) {
            return $event->entity instanceof ClientAccount
                && $event->context['operation'] === 'provision'
                && $event->context['status'] === 'failed'
                && $event->context['idempotency_key'] === "provision:{$account->id}"
                && $event->context['reason'] !== null;
        });
        Event::assertNotDispatched(ProvisioningSucceeded::class);

        $this->assertDatabaseHas('mikrotik_sync_logs', [
            'client_account_id' => $account->id,
            'operation'         => 'provision',
            'status'            => 'failed',
            'idempotency_key'   => "provision:{$account->id}",
        ]);
        $this->assertDatabaseMissing('mikrotik_sync_logs', [
            'client_account_id' => $account->id,
            'status'            => 'success',
        ]);
    }

    #[Test]
    public function job_is_idempotent_for_a_key_that_already_succeeded(): void
    {
        $account = $this->makeAccount('dup-skip');

        // A prior attempt with this key already reached success — replaying the
        // same logical attempt must be a no-op (no second audit row, no throw).
        MikrotikSyncLog::create([
            'tenant_id'         => $this->tenant->id,
            'client_account_id' => $account->id,
            'operation'         => 'provision',
            'status'            => 'success',
            'router_ok'         => true,
            'radius_ok'         => true,
            'idempotency_key'   => "provision:{$account->id}",
            'log_message'       => 'Providing account (previous attempt)',
        ]);

        $job = new ProvisionClientAccountJob($account->id, 'plainsecret', $this->tenant->id, "provision:{$account->id}");
        $job->handle(app(ProvisioningService::class), app(\App\Services\Network\ServiceLifecycleService::class));

        $this->assertSame(1, MikrotikSyncLog::where('client_account_id', $account->id)->count());
        $this->assertNull($account->fresh()->provisioned_at);
    }

    #[Test]
    public function job_uses_exponential_backoff(): void
    {
        $job = new ProvisionClientAccountJob(1, 'secret', 1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff());
        $this->assertSame('provision:1', $job->uniqueId());
    }
    #[Test]
    public function provisioning_status_endpoint_returns_audit_trail(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('super_admin');

        $account = $this->makeAccount('status-user');

        MikrotikSyncLog::create([
            'tenant_id'         => $this->tenant->id,
            'client_account_id' => $account->id,
            'operation'         => 'provision',
            'status'            => 'failed',
            'router_ok'         => false,
            'radius_ok'         => true,
            'failure_reason'    => 'provision: router adapter failed',
            'idempotency_key'   => "provision:{$account->id}",
            'log_message'       => 'Provisioned account status-user (router=fail, radius=ok)',
        ]);
        MikrotikSyncLog::create([
            'tenant_id'         => $this->tenant->id,
            'client_account_id' => $account->id,
            'operation'         => 'activate',
            'status'            => 'success',
            'router_ok'         => true,
            'radius_ok'         => true,
            'idempotency_key'   => "activate:{$account->id}",
            'log_message'       => 'Activated account status-user',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/network/services/{$account->id}/provisioning-status");

        $response->assertOk()
            ->assertJsonPath('account.id', $account->id)
            ->assertJsonPath('account.username', 'status-user')
            ->assertJsonStructure([
                'account' => ['id', 'username', 'service_state', 'is_entitled', 'provisioned_at', 'plan'],
                'latest_status' => ['operation', 'status', 'idempotency_key'],
                'last_provision' => ['status', 'idempotency_key', 'created_at'],
                'retryable',
                'attempts',
                'logs' => [],
            ])
            ->assertJsonPath('logs.0.operation', 'activate')
            ->assertJsonPath('logs.1.status', 'failed')
            ->assertJsonPath('logs.1.failure_reason', 'provision: router adapter failed')
            ->assertJsonPath('retryable', true);
    }

    #[Test]
    public function provisioning_retry_endpoint_queues_job_with_fresh_key(): void
    {
        Queue::fake();

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('super_admin');

        $account = $this->makeAccount('retry-me');

        $response = $this->actingAs($user)
            ->postJson("/api/network/services/{$account->id}/provisioning-retry", ['password' => 'plainsecret']);

        $response->assertOk()
            ->assertJsonPath('queued', true)
            ->assertJsonPath('service_state', ClientAccount::STATE_PENDING);

        $key = $response->json('idempotency_key');
        $this->assertStringStartsWith('retry:provision:', $key);

        Queue::assertPushed(ProvisionClientAccountJob::class, function ($job) use ($account, $key) {
            return $job->accountId === $account->id
                && $job->tenantId === $this->tenant->id
                && $job->idempotencyKey === $key;
        });
    }

    #[Test]
    public function provisioning_retry_rejects_non_stuck_account(): void
    {
        Queue::fake();

        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole('super_admin');

        $account = $this->makeAccount('already-active', ClientAccount::STATE_ACTIVE);

        $this->actingAs($user)
            ->postJson("/api/network/services/{$account->id}/provisioning-retry")
            ->assertStatus(422);

        Queue::assertNotPushed(ProvisionClientAccountJob::class);
    }

    #[Test]
    public function provision_success_recorded_as_automation_event(): void
    {
        // Enable the automation engine for this assertion — it is off globally in
        // the test suite so observer events never mutate state. With it on, the
        // AutomationListener (queued, runs sync here) consumes
        // ProvisioningSucceeded and persists a deduplicated automation_event —
        // proving the provisioning lifecycle is wired into the observable
        // pipeline rather than firing its outcomes into the void.
        $this->app['config']->set('automation.enabled', true);

        $account = $this->makeAccount('automation-pipeline');

        $ok = app(ProvisioningService::class)->provisionAccount($account, 'plainsecret');

        $this->assertTrue($ok);
        $this->assertDatabaseHas('automation_events', [
            'event_class'  => ProvisioningSucceeded::class,
            'type'         => 'provisioning_succeeded',
            'entity_class' => ClientAccount::class,
            'entity_id'    => $account->id,
            'status'       => 'done',
        ]);
    }

    private function failingRouter(): RouterAdapterInterface
    {
        return new class implements RouterAdapterInterface
        {
            public function createUser(array $data): bool { return false; }
            public function deleteUser(string $username): bool { return true; }
            public function suspendUser(string $username): bool { return true; }
            public function unsuspendUser(string $username): bool { return true; }
            public function disconnectSession(string $username): bool { return true; }
            public function testConnection(): bool { return false; }
        };
    }

    private function failingRadius(): RadiusAdapterInterface
    {
        return new class implements RadiusAdapterInterface
        {
            public function createUser(array $data): bool { return false; }
            public function deleteUser(string $username): bool { return true; }
            public function suspendUser(string $username): bool { return true; }
            public function unsuspendUser(string $username): bool { return true; }
            public function syncUsers(): bool { return false; }
            public function syncUsersToAccount(ClientAccount $account): bool { return false; }
            public function changeRateLimit(string $username, string $rate): bool { return false; }
        };
    }
}
