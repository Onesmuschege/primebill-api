<?php

namespace Tests\Feature\Network;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\FupLog;
use App\Models\NetworkEvent;
use App\Models\Plan;
use App\Models\RadiusSession;
use App\Models\Tenant;
use App\Services\Network\FupService;
use App\Services\Radius\MockRadiusAdapter;
use App\Services\Radius\RadiusAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FupServiceTest extends TestCase
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

    /**
     * Swap the container-bound RADIUS adapter for a spy that records
     * changeRateLimit() calls, mirroring the anonymous-adapter pattern
     * used in RADIUSAuthorizationTest.
     */
    protected function spyRadiusAdapter(): object
    {
        $spy = new class extends MockRadiusAdapter
        {
            public array $rateLimitChanges = [];

            public function changeRateLimit(string $username, string $rate): bool
            {
                $this->rateLimitChanges[] = ['username' => $username, 'rate' => $rate];

                return true;
            }
        };

        $this->app->instance(RadiusAdapterInterface::class, $spy);

        return $spy;
    }

    protected function makeAccount(Plan $plan, array $overrides = []): ClientAccount
    {
        return ClientAccount::factory()->create(array_merge([
            'tenant_id'     => $this->tenant->id,
            'client_id'     => $this->client->id,
            'plan_id'       => $plan->id,
            'username'      => 'fupuser01',
            'password'      => bcrypt('secret'),
            'type'          => 'prepaid',
            'status'        => 'active',
            'service_state' => ClientAccount::STATE_ACTIVE,
            'activated_at'  => now()->subDays(5),
        ], $overrides));
    }

    protected function addUsage(ClientAccount $account, int $bytesIn, int $bytesOut): void
    {
        RadiusSession::create([
            'username'          => $account->username,
            'client_account_id' => $account->id,
            'session_start'     => now()->subHour(),
            'bytes_in'          => $bytesIn,
            'bytes_out'         => $bytesOut,
            'status'            => 'online',
        ]);
    }

    #[Test]
    public function evaluate_triggers_throttling_when_usage_exceeds_fup_limit(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'name'           => 'FupPlan',
            'speed_up'       => 5120,
            'speed_down'     => 10240,
            'fup_limit'      => 1,   // limit = 1 * 1024 * 1024 bytes
            'fup_speed_up'   => 512,
            'fup_speed_down' => 1024,
        ]);

        $account = $this->makeAccount($plan);

        // 1.4 MB total usage against a 1 MB limit
        $this->addUsage($account, 700 * 1024, 700 * 1024);

        $spy = $this->spyRadiusAdapter();

        app(FupService::class)->evaluate($account);

        $account->refresh();

        // FUP log marks the trigger
        $fupLog = FupLog::where('client_account_id', $account->id)->first();
        $this->assertNotNull($fupLog);
        $this->assertNotNull($fupLog->triggered_at);
        $this->assertEquals(700 * 1024 + 700 * 1024, $fupLog->bytes_used);

        // Account throttled to the plan's FUP speeds
        $this->assertSame('512k/1024k', $account->rate_limit_policy);

        // Rate limit pushed to the RADIUS adapter
        $this->assertCount(1, $spy->rateLimitChanges);
        $this->assertSame($account->username, $spy->rateLimitChanges[0]['username']);
        $this->assertSame('512k/1024k', $spy->rateLimitChanges[0]['rate']);

        // Network event recorded
        $this->assertTrue(
            NetworkEvent::where('event_type', 'FUP_TRIGGERED')
                ->where('client_account_id', $account->id)
                ->exists()
        );
    }

    #[Test]
    public function evaluate_does_not_trigger_when_usage_is_below_limit(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'name'           => 'FupPlanSmall',
            'speed_up'       => 5120,
            'speed_down'     => 10240,
            'fup_limit'      => 1,
            'fup_speed_up'   => 512,
            'fup_speed_down' => 1024,
        ]);

        $account = $this->makeAccount($plan);

        // 200 KB total, well under the 1 MB limit
        $this->addUsage($account, 100 * 1024, 100 * 1024);

        $spy = $this->spyRadiusAdapter();

        app(FupService::class)->evaluate($account);

        $account->refresh();

        $fupLog = FupLog::where('client_account_id', $account->id)->first();
        $this->assertNotNull($fupLog);
        $this->assertNull($fupLog->triggered_at);
        $this->assertEquals(100 * 1024 + 100 * 1024, $fupLog->bytes_used);

        $this->assertNull($account->rate_limit_policy);
        $this->assertCount(0, $spy->rateLimitChanges);
        $this->assertFalse(
            NetworkEvent::where('event_type', 'FUP_TRIGGERED')
                ->where('client_account_id', $account->id)
                ->exists()
        );
    }

    #[Test]
    public function evaluate_does_not_retrigger_when_fup_already_triggered(): void
    {
        $plan = Plan::factory()->create([
            'tenant_id'      => $this->tenant->id,
            'name'           => 'FupPlanRepeat',
            'speed_up'       => 5120,
            'speed_down'     => 10240,
            'fup_limit'      => 1,
            'fup_speed_up'   => 512,
            'fup_speed_down' => 1024,
        ]);

        $account = $this->makeAccount($plan);

        FupLog::create([
            'client_account_id' => $account->id,
            'bytes_used'        => 2 * 1024 * 1024,
            'triggered_at'      => now()->subDay(),
        ]);

        // Still over the limit on the next hourly run
        $this->addUsage($account, 1024 * 1024, 1024 * 1024);

        $spy = $this->spyRadiusAdapter();

        app(FupService::class)->evaluate($account);

        $account->refresh();

        // No new rate-limit push and no new event — throttle already applied
        $this->assertCount(0, $spy->rateLimitChanges);
        $this->assertNull($account->rate_limit_policy);
        $this->assertSame(
            0,
            NetworkEvent::where('event_type', 'FUP_TRIGGERED')
                ->where('client_account_id', $account->id)
                ->count()
        );
    }
}
