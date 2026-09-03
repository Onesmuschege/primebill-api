<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Radius\CoaResult;
use App\Services\Radius\RadiusCoaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test as Test;
use Tests\TestCase;

/**
 * Covers the pre-flight failure modes of RadiusCoaClient that must
 * short-circuit BEFORE any UDP traffic (Section 21 — never claim success
 * when the NAS is unreachable / misconfigured).
 */
class CoaClientFailureModeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);
    }

    protected function tearDown(): void
    {
        Tenant::setCurrent(null);
        parent::tearDown();
    }

    #[Test]
    public function change_rate_returns_no_nas_when_account_has_no_router(): void
    {
        $plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'username' => 'no-router',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);
        $account->setRelation('router', null);

        $result = app(RadiusCoaClient::class)->changeRate($account, '5000k/10000k');

        $this->assertInstanceOf(CoaResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertSame('no_nas', $result->reasonCode);
    }

    #[Test]
    public function change_rate_returns_no_secret_when_router_lacks_secret(): void
    {
        $router = Router::factory()->create([
            'tenant_id' => $this->tenant->id,
            'ip_address' => '10.0.0.2',
            'username' => 'admin',
            'type' => 'mikrotik',
            'radius_secret_encrypted' => null,
        ]);
        $plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'username' => 'no-secret',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);
        $account->setRelation('router', $router);

        $result = app(RadiusCoaClient::class)->changeRate($account, '3000k/5000k');

        $this->assertFalse($result->success);
        $this->assertSame('no_secret', $result->reasonCode);
    }

    #[Test]
    public function disconnect_with_no_router_is_truthful_failure(): void
    {
        $plan = Plan::factory()->create(['tenant_id' => $this->tenant->id]);
        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'username' => 'disc-no-nas',
            'service_state' => ClientAccount::STATE_ACTIVE,
        ]);
        $account->setRelation('router', null);

        $result = app(RadiusCoaClient::class)->disconnect($account);

        $this->assertFalse($result->success);
        $this->assertSame('no_nas', $result->reasonCode);
    }
}
