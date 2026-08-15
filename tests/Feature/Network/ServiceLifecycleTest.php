<?php

namespace Tests\Feature\Network;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private string $token;
    private Client $client;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test')->plainTextToken;

        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'active']);
        $this->plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    #[Test]
    public function service_transitions_through_valid_states(): void
    {
        $account = ClientAccount::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $this->plan->id,
            'username' => 'lifecycle01',
            'password' => bcrypt('secret'),
            'type' => 'prepaid',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_PENDING,
        ]);

        // PENDING -> PROVISIONING
        $this->assertTrue($account->transitionTo(ClientAccount::STATE_PROVISIONING));
        $this->assertEquals(ClientAccount::STATE_PROVISIONING, $account->service_state);

        // PROVISIONING -> ACTIVE
        $this->assertTrue($account->transitionTo(ClientAccount::STATE_ACTIVE));
        $this->assertEquals(ClientAccount::STATE_ACTIVE, $account->service_state);

        // ACTIVE -> SUSPENDED
        $this->assertTrue($account->transitionTo(ClientAccount::STATE_SUSPENDED));
        $this->assertEquals(ClientAccount::STATE_SUSPENDED, $account->service_state);

        // SUSPENDED -> ACTIVE (restore)
        $this->assertTrue($account->transitionTo(ClientAccount::STATE_ACTIVE));
        $this->assertEquals(ClientAccount::STATE_ACTIVE, $account->service_state);

        // ACTIVE -> TERMINATED
        $this->assertTrue($account->transitionTo(ClientAccount::STATE_TERMINATED));
        $this->assertEquals(ClientAccount::STATE_TERMINATED, $account->service_state);
    }

    #[Test]
    public function service_rejects_invalid_state_transitions(): void
    {
        $account = ClientAccount::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $this->plan->id,
            'username' => 'invalidtrans',
            'password' => bcrypt('secret'),
            'type' => 'prepaid',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_PENDING,
        ]);

        // PENDING cannot go directly to ACTIVE
        $this->assertFalse($account->transitionTo(ClientAccount::STATE_ACTIVE));

        // PENDING cannot go to SUSPENDED
        $this->assertFalse($account->transitionTo(ClientAccount::STATE_SUSPENDED));

        // TERMINATED is a dead end
        $account->service_state = ClientAccount::STATE_TERMINATED;
        $this->assertFalse($account->transitionTo(ClientAccount::STATE_ACTIVE));
        $this->assertFalse($account->transitionTo(ClientAccount::STATE_PENDING));
    }

    #[Test]
    public function service_transition_records_network_event(): void
    {
        $account = ClientAccount::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $this->plan->id,
            'username' => 'eventtest',
            'password' => bcrypt('secret'),
            'type' => 'prepaid',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_PENDING,
        ]);

        // PENDING -> PROVISIONING -> ACTIVE (valid path)
        $this->assertTrue($account->transitionTo(ClientAccount::STATE_PROVISIONING));
        $this->assertTrue($account->transitionTo(ClientAccount::STATE_ACTIVE, 'Provisioning complete'));

        $this->assertDatabaseHas('network_events', [
            'tenant_id' => $this->tenant->id,
            'client_account_id' => $account->id,
            'event_type' => 'SERVICE_STATE_CHANGED',
            'severity' => 'info',
        ]);
    }

    #[Test]
    public function api_can_suspend_and_resume_service(): void
    {
        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $this->plan->id,
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);

        // Suspend
        $response = $this->postJson("/api/clients/{$this->client->id}/subscriptions", [
            'plan_id' => $this->plan->id,
            'name' => 'Suspend Test',
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        // Use the service network endpoint directly
        $response = $this->postJson("/api/network/services/{$account->id}/suspend", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $account->refresh();
        $this->assertEquals(ClientAccount::STATE_SUSPENDED, $account->service_state);

        // Resume
        $response = $this->postJson("/api/network/services/{$account->id}/restore", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $account->refresh();
        $this->assertEquals(ClientAccount::STATE_ACTIVE, $account->service_state);
    }

    #[Test]
    public function service_can_enter_grace_period_from_past_due(): void
    {
        $account = ClientAccount::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $this->plan->id,
            'username' => 'grace01',
            'password' => bcrypt('secret'),
            'type' => 'prepaid',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_PAST_DUE,
        ]);

        $this->assertTrue($account->transitionTo(ClientAccount::STATE_GRACE_PERIOD));
        $this->assertEquals(ClientAccount::STATE_GRACE_PERIOD, $account->service_state);
    }

    #[Test]
    public function grace_period_can_return_to_active(): void
    {
        $account = ClientAccount::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'plan_id' => $this->plan->id,
            'username' => 'grace02',
            'password' => bcrypt('secret'),
            'type' => 'prepaid',
            'status' => 'active',
            'service_state' => ClientAccount::STATE_GRACE_PERIOD,
        ]);

        $this->assertTrue($account->transitionTo(ClientAccount::STATE_ACTIVE));
        $this->assertEquals(ClientAccount::STATE_ACTIVE, $account->service_state);
    }
}
