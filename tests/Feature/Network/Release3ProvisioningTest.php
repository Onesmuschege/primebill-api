<?php

namespace Tests\Feature\Network;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\NetworkEvent;
use App\Models\MikrotikSyncLog;
use App\Services\Network\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Release3ProvisioningTest extends TestCase
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
        $this->plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Tenant::setCurrent(null);
        parent::tearDown();
    }

    #[Test]
    public function reconcile_all_suspends_entitled_account_that_has_overdue_invoice(): void
    {
        $account = ClientAccount::factory()->create([
            'tenant_id'    => $this->tenant->id,
            'client_id'    => $this->client->id,
            'plan_id'      => $this->plan->id,
            'username'     => 'reconcile-suspend',
            'status'       => 'active',
            'service_state'=> ClientAccount::STATE_ACTIVE,
        ]);

        Invoice::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'client_id'  => $this->client->id,
            'status'     => 'overdue',
            'due_date'   => now()->subDays(5),
        ]);

        $stats = app(\App\Services\Network\ServiceLifecycleService::class)->reconcileAll();

        $this->assertSame(1, $stats['suspended']);
        $this->assertSame(1, $stats['checked']);

        $account->refresh();
        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);

        $this->assertDatabaseHas('network_events', [
            'tenant_id'         => $this->tenant->id,
            'event_type'        => 'SERVICE_SUSPENDED',
            'client_account_id' => $account->id,
        ]);
    }

        #[Test]
    public function reconcile_all_restores_suspended_account_when_client_is_entitled(): void
    {
        $account = ClientAccount::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'client_id'      => $this->client->id,
            'plan_id'        => $this->plan->id,
            'username'       => 'reconcile-restore',
            'status'         => 'suspended',
            'service_state'  => ClientAccount::STATE_SUSPENDED,
        ]);

        $stats = app(\App\Services\Network\ServiceLifecycleService::class)->reconcileAll();

        $this->assertSame(1, $stats['restored']);
        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame(ClientAccount::STATE_ACTIVE, $account->service_state);

        $this->assertDatabaseHas('network_events', [
            'tenant_id'         => $this->tenant->id,
            'event_type'        => 'SERVICE_ACTIVATED',
            'client_account_id' => $account->id,
        ]);
    }

    #[Test]
    public function provisioning_service_records_structured_log_on_activate_success(): void
    {
        $account = ClientAccount::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'client_id'      => $this->client->id,
            'plan_id'        => $this->plan->id,
            'username'       => 'structured-activate',
            'status'         => 'active',
            'service_state'  => ClientAccount::STATE_SUSPENDED,
        ]);

        $ok = app(ProvisioningService::class)->activateAccount($account);

        $this->assertTrue($ok);

        $this->assertDatabaseHas('mikrotik_sync_logs', [
            'tenant_id'         => $this->tenant->id,
            'client_account_id' => $account->id,
            'operation'         => 'activate',
            'status'            => 'success',
            'router_ok'         => true,
            'radius_ok'         => true,
            'failure_reason'    => null,
        ]);
    }

    #[Test]
    public function provisioning_service_records_failure_when_account_has_no_plan(): void
    {
        $account = ClientAccount::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'client_id'      => $this->client->id,
            'plan_id'        => null,
            'username'       => 'structured-nolog',
            'status'         => 'active',
            'service_state'  => ClientAccount::STATE_PENDING,
        ]);

        $ok = app(ProvisioningService::class)->provisionAccount($account, 'plain-secret');

        $this->assertFalse($ok);

        $this->assertDatabaseHas('mikrotik_sync_logs', [
            'tenant_id'         => $this->tenant->id,
            'client_account_id' => $account->id,
            'operation'         => 'provision',
            'status'            => 'failed',
            'failure_reason'    => 'No plan assigned',
        ]);
    }

    #[Test]
    public function evaluate_fup_command_runs_and_returns_zero_exit(): void
    {
        $this->artisan('network:evaluate-fup')->assertExitCode(0);
    }

    #[Test]
    public function retry_failed_provisioning_command_runs_and_returns_zero_exit(): void
    {
        $this->artisan('network:retry-failed-provisioning')->assertExitCode(0);
    }

    #[Test]
    public function fup_service_can_be_resolved_without_a_broken_dependency(): void
    {
        // Regression for the R3 bug where FupService type-hinted
        // App\Services\Network\RadiusControlService (which did not exist).
        $this->assertInstanceOf(
            \App\Services\Network\FupService::class,
            app(\App\Services\Network\FupService::class)
        );
    }
}