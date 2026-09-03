<?php

namespace Tests\Feature\Network;

use PHPUnit\Framework\Attributes\Test;

use App\Jobs\ProvisionClientAccountJob;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Network\PppoeAccessService;
use App\Services\Network\RouterAdapterInterface;
use App\Services\Network\ServiceLifecycleService;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Core ISP Gate regression tests.
 *
 * Covers the P0 contracts that make a real ISP operation real rather than a
 * database simulation:
 *
 *   - payments extend exactly the invoiced service (multi-service safety)
 *   - disconnect terminates live sessions without deleting credentials
 *   - suspension hard-disconnects the live session (option A)
 *   - prepaid expiry ends entitlement truthfully
 *   - service creation reflects provisioning state truthfully
 *   - bandwidth-policy application reaches the RADIUS backend
 */
class CoreIspOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Client $client;
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

    protected function makeAccount(array $overrides = []): ClientAccount
    {
        return ClientAccount::factory()->create(array_merge([
            'tenant_id'      => $this->tenant->id,
            'client_id'      => $this->client->id,
            'plan_id'        => Plan::factory()->create([
                'tenant_id'     => $this->tenant->id,
                'validity_days' => 30,
                'type'          => 'pppoe',
            ])->id,
            'username'       => 'core-' . uniqid(),
            'password'       => bcrypt('secret'),
            'type'           => 'prepaid',
            'status'         => 'active',
            'service_state'  => ClientAccount::STATE_ACTIVE,
            'expiry_date'    => now()->addDays(10),
        ], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Section 9 / 28 — Commercial Transaction Identity (multi-service)
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function payment_extends_exactly_the_invoiced_service_not_the_first_account(): void
    {
        $fibre = $this->makeAccount(['username' => 'fibre-customer', 'expiry_date' => now()->addDays(10)]);
        $hotspot = $this->makeAccount(['username' => 'hotspot-customer', 'expiry_date' => now()->subDays(2)]);

        $invoice = Invoice::create([
            'client_id'         => $this->client->id,
            'client_account_id' => $hotspot->id,
            'invoice_number'    => 'INV-MULTI-' . uniqid(),
            'amount'            => 1000,
            'tax'               => 0,
            'total'             => 1000,
            'status'            => 'unpaid',
            'due_date'          => now()->addDays(7),
        ]);

        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);

        $paymentService->recordPayment([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 1000,
            'method'     => 'cash',
            'reference'  => 'REF-MULTI-' . uniqid(),
        ], $this->user->id);

        $fibre->refresh();
        $hotspot->refresh();

        // Fibre is untouched.
        $this->assertEquals(now()->addDays(10)->toDateString(), $fibre->expiry_date->toDateString());

        // The invoiced hotspot service — already expired — renews from NOW
        // with the plan's 30-day validity.
        $this->assertTrue($hotspot->expiry_date->isFuture());
        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $hotspot->expiry_date->toDateString()
        );
    }

    #[Test]
    public function payment_for_fibre_invoice_does_not_renew_hotspot_service(): void
    {
        $hotspot = $this->makeAccount(['username' => 'hotspot-solo', 'expiry_date' => now()->addDays(20)]);
        $fibre = $this->makeAccount(['username' => 'fibre-solo', 'expiry_date' => now()->subDays(1)]);

        $invoice = Invoice::create([
            'client_id'         => $this->client->id,
            'client_account_id' => $fibre->id,
            'invoice_number'    => 'INV-MULTI2-' . uniqid(),
            'amount'            => 2000,
            'tax'               => 0,
            'total'             => 2000,
            'status'            => 'unpaid',
            'due_date'          => now()->addDays(7),
        ]);

        app(PaymentService::class)->recordPayment([
            'client_id'  => $this->client->id,
            'invoice_id' => $invoice->id,
            'amount'     => 2000,
            'method'     => 'mpesa',
            'reference'  => 'REF-MULTI2-' . uniqid(),
        ], $this->user->id);

        $hotspot->refresh();
        $fibre->refresh();

        // Hotspot untouched.
        $this->assertEquals(now()->addDays(20)->toDateString(), $hotspot->expiry_date->toDateString());

        // Fibre renewed.
        $this->assertTrue($fibre->expiry_date->isFuture());
    }

    #[Test]
    public function bulk_invoice_generation_creates_one_invoice_per_service(): void
    {
        $planA = Plan::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 1000]);
        $planB = Plan::factory()->create(['tenant_id' => $this->tenant->id, 'price' => 2000]);

        $serviceA = $this->makeAccount(['plan_id' => $planA->id]);
        $serviceB = $this->makeAccount(['plan_id' => $planB->id]);

        $invoiceService = app(\App\Services\Billing\InvoiceService::class);

        $invoiceService->bulkGenerate([$this->client->id], $this->user->id);

        // One invoice per service, each linked to exactly that service.
        $this->assertDatabaseHas('invoices', [
            'client_id'         => $this->client->id,
            'client_account_id' => $serviceA->id,
            'status'            => 'unpaid',
        ]);
        $this->assertDatabaseHas('invoices', [
            'client_id'         => $this->client->id,
            'client_account_id' => $serviceB->id,
            'status'            => 'unpaid',
        ]);

        // A second run must NOT duplicate per-service invoices.
        $invoiceService->bulkGenerate([$this->client->id], $this->user->id);

        $invoiceCount = Invoice::where('client_id', $this->client->id)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->count();

        $this->assertSame(2, $invoiceCount);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Section 19 / 20 — Disconnect ≠ delete; suspension hard-disconnects
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function disconnect_session_terminates_live_session_without_deleting_credential(): void
    {
        $spy = new class implements RouterAdapterInterface {
            public int $deleteCalls = 0;
            public int $disconnectCalls = 0;

            public function createUser(array $data): bool { return true; }
            public function deleteUser(string $username): bool { $this->deleteCalls++; return true; }
            public function suspendUser(string $username): bool { return true; }
            public function unsuspendUser(string $username): bool { return true; }
            public function disconnectSession(string $username): bool { $this->disconnectCalls++; return true; }
            public function testConnection(): bool { return true; }
        };

        $this->app->instance(RouterAdapterInterface::class, $spy);

        $account = $this->makeAccount(['access_method' => ClientAccount::ACCESS_PPPOE]);

        /** @var PppoeAccessService $pppoe */
        $pppoe = app(PppoeAccessService::class);
        $pppoe->disconnectSession($account);

        $this->assertSame(1, $spy->disconnectCalls, 'disconnect must target the live session');
        $this->assertSame(0, $spy->deleteCalls, 'disconnect must NOT delete the credential');
    }

    #[Test]
    public function suspension_hard_disconnects_active_session(): void
    {
        $spy = new class implements RouterAdapterInterface {
            public int $suspendCalls = 0;
            public int $disconnectCalls = 0;

            public function createUser(array $data): bool { return true; }
            public function deleteUser(string $username): bool { return true; }
            public function suspendUser(string $username): bool { $this->suspendCalls++; return true; }
            public function unsuspendUser(string $username): bool { return true; }
            public function disconnectSession(string $username): bool { $this->disconnectCalls++; return true; }
            public function testConnection(): bool { return true; }
        };

        $this->app->instance(RouterAdapterInterface::class, $spy);

        $account = $this->makeAccount(['access_method' => ClientAccount::ACCESS_PPPOE]);

        app(ServiceLifecycleService::class)->suspend(
            $account,
            'test-suspension',
            ServiceLifecycleService::SUSPENSION_BILLING,
            $this->user->id
        );

        $this->assertSame(1, $spy->suspendCalls);
        $this->assertSame(1, $spy->disconnectCalls, 'suspension must drop the live session');

        $account->refresh();
        $this->assertSame('suspended', $account->status);
        $this->assertSame(ClientAccount::STATE_SUSPENDED, $account->service_state);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Section 26 — prepaid expiry ends entitlement truthfully
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function expired_prepaid_service_is_not_entitled(): void
    {
        $account = $this->makeAccount([
            'type'        => 'prepaid',
            'expiry_date' => now()->subDay(),
        ]);

        $this->assertFalse(app(ServiceLifecycleService::class)->isEntitled($account));
    }

    #[Test]
    public function postpaid_service_is_not_suspended_by_expiry_column(): void
    {
        $account = $this->makeAccount([
            'type'        => 'postpaid',
            'expiry_date' => now()->subDay(),
        ]);

        // Postpaid entitlement follows the invoice position, not the legacy
        // expiry column — no overdue debt here.
        $this->assertTrue(app(ServiceLifecycleService::class)->isEntitled($account));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Section 15 — truthful provisioning state at creation
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function account_creation_starts_pending_and_job_activates_it(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'validity_days' => 30,
            'type'          => 'pppoe',
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/accounts", [
            'plan_id'  => $plan->id,
            'username' => 'pending-create-' . uniqid(),
            'password' => 'secret123',
        ], ['Authorization' => "Bearer {$this->token}"]);

        $response->assertStatus(201);

        $username = $response->json('data.username');

        // Sync queue means the provisioning job already ran — with mock
        // adapters it succeeds, so the authoritative lifecycle marks ACTIVE.
        $account = ClientAccount::where('username', $username)->firstOrFail();
        $this->assertSame('active', $account->status);
        $this->assertSame(ClientAccount::STATE_ACTIVE, $account->service_state);
        $this->assertNotNull($account->provisioned_at);
    }

    #[Test]
    public function provisioning_job_leaves_service_pending_when_network_fails(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id'     => $this->tenant->id,
            'validity_days' => 30,
            'type'          => 'pppoe',
        ]);

        $account = ClientAccount::create([
            'tenant_id'     => $this->tenant->id,
            'client_id'     => $this->client->id,
            'plan_id'       => $plan->id,
            'username'      => 'fail-provision-' . uniqid(),
            'password'      => bcrypt('secret'),
            'type'          => 'prepaid',
            'status'        => 'pending',
            'service_state' => ClientAccount::STATE_PENDING,
        ]);

        $failAdapter = new class implements RouterAdapterInterface {
            public function createUser(array $data): bool { return false; }
            public function deleteUser(string $username): bool { return true; }
            public function suspendUser(string $username): bool { return true; }
            public function unsuspendUser(string $username): bool { return true; }
            public function disconnectSession(string $username): bool { return true; }
            public function testConnection(): bool { return false; }
        };

        $this->app->instance(RouterAdapterInterface::class, $failAdapter);

        app(ProvisionClientAccountJob::class, [
            'accountId'     => $account->id,
            'plainPassword' => 'secret',
            'tenantId'      => $this->tenant->id,
        ])->handle(
            app(\App\Services\Network\ProvisioningService::class),
            app(ServiceLifecycleService::class)
        );

        $account->refresh();
        $this->assertSame('pending', $account->status);
        $this->assertSame(ClientAccount::STATE_PENDING, $account->service_state);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Section 21/22 — bandwidth policy change reaches the RADIUS backend
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function applying_bandwidth_policy_writes_rate_limit_to_radius(): void
    {
        $radiusSpy = new class implements RadiusAdapterInterface {
            public array $rateLimitCalls = [];

            public function createUser(array $data): bool { return true; }
            public function deleteUser(string $username): bool { return true; }
            public function suspendUser(string $username): bool { return true; }
            public function unsuspendUser(string $username): bool { return true; }
            public function syncUsers(): bool { return true; }
            public function syncUsersToAccount(\App\Models\ClientAccount $account): bool { return true; }
            public function changeRateLimit(string $username, string $rate): bool
            {
                $this->rateLimitCalls[] = ['username' => $username, 'rate' => $rate];
                return true;
            }
        };

        $this->app->instance(RadiusAdapterInterface::class, $radiusSpy);

        $account = $this->makeAccount(['username' => 'bw-policy-' . uniqid()]);

        /** @var PppoeAccessService $pppoe */
        $pppoe = app(PppoeAccessService::class);
        $ok = $pppoe->applyBandwidthPolicy($account, [
            'download_speed' => 2048,
            'upload_speed'   => 512,
        ]);

        $this->assertTrue($ok);
        $this->assertCount(1, $radiusSpy->rateLimitCalls);
        $this->assertSame('512k/2048k', $radiusSpy->rateLimitCalls[0]['rate']);
    }
}