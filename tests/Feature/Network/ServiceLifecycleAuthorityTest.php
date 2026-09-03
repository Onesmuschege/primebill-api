<?php

namespace Tests\Feature\Network;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\NetworkEvent;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Email\EmailService;
use App\Services\Network\RouterAdapterInterface;
use App\Services\Network\ServiceLifecycleService;
use App\Services\Sms\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 1 — SL1 unified lifecycle authority + SL2 administrative holds.
 */
class ServiceLifecycleAuthorityTest extends TestCase
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

    protected function tearDown(): void
    {
        Tenant::setCurrent(null);
        parent::tearDown();
    }

    protected function makeAccount(array $overrides = []): ClientAccount
    {
        return ClientAccount::factory()->create(array_merge([
            'tenant_id'     => $this->tenant->id,
            'client_id'     => $this->client->id,
            'plan_id'       => $this->plan->id,
            'status'        => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ], $overrides));
    }

    protected function bindCountingRouterAdapter(): object
    {
        $spy = new class implements RouterAdapterInterface {
            public int $suspendCalls = 0;
            public int $unsuspendCalls = 0;
            public int $disconnectCalls = 0;

            public function createUser(array $data): bool { return true; }
            public function deleteUser(string $username): bool { return true; }
            public function suspendUser(string $username): bool { $this->suspendCalls++; return true; }
            public function unsuspendUser(string $username): bool { $this->unsuspendCalls++; return true; }
            public function disconnectSession(string $username): bool { $this->disconnectCalls++; return true; }
            public function testConnection(): bool { return true; }
        };

        $this->app->instance(RouterAdapterInterface::class, $spy);

        return $spy;
    }

    // ─── Unified state (SL1) ─────────────────────────────────────────────

    #[Test]
    public function suspend_via_authority_keeps_status_and_service_state_consistent(): void
    {
        $account = $this->makeAccount();

        app(ServiceLifecycleService::class)->suspend($account, 'test', ServiceLifecycleService::SUSPENSION_BILLING);
        $account->refresh();

        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);
        $this->assertSame(ClientAccount::SUSPENSION_BILLING, $account->suspension_type);
    }

    #[Test]
    public function activate_via_authority_keeps_status_and_service_state_consistent(): void
    {
        $account = $this->makeAccount(['status' => 'suspended', 'service_state' => ClientAccount::STATE_SUSPENDED]);
        $account->update(['suspension_type' => ClientAccount::SUSPENSION_BILLING]);

        app(ServiceLifecycleService::class)->activate($account, 'test');
        $account->refresh();

        $this->assertSame('active', $account->status);
        $this->assertSame(ClientAccount::STATE_ACTIVE, $account->service_state);
        $this->assertNull($account->suspension_type);
    }

    // ─── Administrative hold (SL2) ───────────────────────────────────────

    #[Test]
    public function api_suspend_marks_administrative_hold(): void
    {
        $account = $this->makeAccount();

        $this->postJson("/api/network/services/{$account->id}/suspend", [
            'reason' => 'Operator inspection',
        ], ['Authorization' => "Bearer {$this->token}"])->assertStatus(200);

        $account->refresh();

        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);
        $this->assertSame(ClientAccount::SUSPENSION_ADMIN, $account->suspension_type);
        $this->assertSame($this->user->id, $account->suspended_by);
        $this->assertTrue($account->hasAdministrativeHold());
    }

    #[Test]
    public function reconciliation_does_not_restore_administratively_held_account(): void
    {
        // Entitled client — without the SL2 rule reconciliation would restore.
        $account = $this->makeAccount(['status' => 'suspended', 'service_state' => ClientAccount::STATE_SUSPENDED]);
        $account->update(['suspension_type' => ClientAccount::SUSPENSION_ADMIN]);

        $stats = app(ServiceLifecycleService::class)->reconcileAll();

        $this->assertSame(0, $stats['restored']);
        $this->assertSame(1, $stats['admin_hold_skipped']);

        $account->refresh();
        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);
        $this->assertSame(ClientAccount::SUSPENSION_ADMIN, $account->suspension_type);
    }

    #[Test]
    public function explicit_admin_restore_removes_administrative_hold(): void
    {
        $account = $this->makeAccount(['status' => 'suspended', 'service_state' => ClientAccount::STATE_SUSPENDED]);
        $account->update(['suspension_type' => ClientAccount::SUSPENSION_ADMIN]);

        $this->postJson("/api/network/services/{$account->id}/restore", [], [
            'Authorization' => "Bearer {$this->token}",
        ])->assertStatus(200);

        $account->refresh();

        $this->assertSame('active', $account->status);
        $this->assertSame(ClientAccount::STATE_ACTIVE, $account->service_state);
        $this->assertNull($account->suspension_type);
        $this->assertFalse($account->hasAdministrativeHold());
    }

    #[Test]
    public function billing_suspension_is_restored_by_reconciliation(): void
    {
        $account = $this->makeAccount(['status' => 'suspended', 'service_state' => ClientAccount::STATE_SUSPENDED]);
        $account->update(['suspension_type' => ClientAccount::SUSPENSION_BILLING]);

        $stats = app(ServiceLifecycleService::class)->reconcileAll();

        $this->assertSame(1, $stats['restored']);

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame(ClientAccount::STATE_ACTIVE, $account->service_state);
        $this->assertNull($account->suspension_type);
    }

    // ─── Idempotency ─────────────────────────────────────────────────────

    #[Test]
    public function repeated_suspend_is_idempotent_and_does_not_duplicate_network_calls(): void
    {
        $spy = $this->bindCountingRouterAdapter();
        $lifecycle = app(ServiceLifecycleService::class);
        $account = $this->makeAccount();

        $this->assertTrue($lifecycle->suspend($account, 'first', ServiceLifecycleService::SUSPENSION_BILLING));
        $this->assertFalse($lifecycle->suspend($account, 'again', ServiceLifecycleService::SUSPENSION_BILLING));

        // Re-issuing an operator suspension upgrades the classification to an
        // administrative hold WITHOUT repeating destructive network work.
        $lifecycle->suspend($account, 'operator', ServiceLifecycleService::SUSPENSION_ADMIN, $this->user->id);

        $account->refresh();
        $this->assertSame(ClientAccount::SUSPENSION_ADMIN, $account->suspension_type);

        $this->assertSame(1, $spy->suspendCalls, 'Router must be touched exactly once.');
        $this->assertSame(1, NetworkEvent::where('client_account_id', $account->id)->where('event_type', 'SERVICE_SUSPENDED')->count());
    }

    #[Test]
    public function repeated_restore_is_safe(): void
    {
        $spy = $this->bindCountingRouterAdapter();
        $lifecycle = app(ServiceLifecycleService::class);
        $account = $this->makeAccount(['status' => 'suspended', 'service_state' => ClientAccount::STATE_SUSPENDED]);

        $this->assertTrue($lifecycle->activate($account, 'first'));
        $this->assertTrue($lifecycle->activate($account, 'again'));

        $this->assertSame(1, $spy->unsuspendCalls, 'Router restore must run exactly once.');
        $this->assertSame(1, NetworkEvent::where('client_account_id', $account->id)->where('event_type', 'SERVICE_ACTIVATED')->count());
    }

    // ─── Tenant isolation ────────────────────────────────────────────────

    #[Test]
    public function reconciliation_is_tenant_isolated(): void
    {
        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $otherClient = Client::factory()->create(['tenant_id' => $otherTenant->id, 'status' => 'active']);

        // Tenant A: entitled, billing-suspended → must be restored.
        $accountA = $this->makeAccount(['status' => 'suspended', 'service_state' => ClientAccount::STATE_SUSPENDED]);
        $accountA->update(['suspension_type' => ClientAccount::SUSPENSION_BILLING]);

        // Tenant B: overdue, active → must be suspended, scoped to its own tenant.
        $accountB = ClientAccount::factory()->create([
            'tenant_id'     => $otherTenant->id,
            'client_id'     => $otherClient->id,
            'plan_id'       => Plan::factory()->create(['tenant_id' => $otherTenant->id])->id,
            'status'        => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);

        Invoice::factory()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'status'    => 'overdue',
            'due_date'  => now()->subDays(5),
        ]);

        app(ServiceLifecycleService::class)->reconcileAll();

        $accountA->refresh();
        $accountB->refresh();

        $this->assertSame('active', $accountA->status);
        $this->assertSame(ClientAccount::STATE_ACTIVE, $accountA->service_state);
        $this->assertSame('suspended', $accountB->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $accountB->service_state);
        $this->assertSame(ClientAccount::SUSPENSION_BILLING, $accountB->suspension_type);
    }

    // ─── Callers route through the authority ─────────────────────────────

    #[Test]
    public function suspend_overdue_command_records_billing_suspension(): void
    {
        // Notification side-effects only — the command must not hit real
        // gateways in tests.
        $this->mock(SmsService::class, function ($mock) {
            $mock->shouldReceive('send')->andReturn(true);
        });
        $this->mock(EmailService::class, function ($mock) {
            $mock->shouldReceive('accountSuspendedEmail')->andReturn(true);
        });

        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status'    => 'overdue',
            'due_date'  => now()->subDays(5),
        ]);

        $account = $this->makeAccount();

        $this->artisan('billing:suspend-overdue');

        $account->refresh();
        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);
        $this->assertSame(ClientAccount::SUSPENSION_BILLING, $account->suspension_type);
    }

    #[Test]
    public function client_account_update_routes_suspension_through_authority(): void
    {
        $account = $this->makeAccount();

        $this->putJson("/api/clients/{$this->client->id}/accounts/{$account->id}", [
            'status' => 'suspended',
        ], ['Authorization' => "Bearer {$this->token}"])->assertStatus(200);

        $account->refresh();
        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);
        $this->assertSame(ClientAccount::SUSPENSION_ADMIN, $account->suspension_type);
    }

    // ─── Truthful entitlement (Section 7) ────────────────────────────────

    #[Test]
    public function status_endpoint_reports_truthful_entitlement(): void
    {
        $account = $this->makeAccount();

        $this->getJson("/api/network/services/{$account->id}/status", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertStatus(200)->assertJsonPath('is_entitled', true);

        Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'status'    => 'overdue',
            'due_date'  => now()->subDays(5),
        ]);

        $this->getJson("/api/network/services/{$account->id}/status", [
            'Authorization' => "Bearer {$this->token}",
        ])->assertJsonPath('is_entitled', false);
    }
}
