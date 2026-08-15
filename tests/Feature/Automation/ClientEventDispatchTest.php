<?php

namespace Tests\Feature\Automation;

use PHPUnit\Framework\Attributes\Test;

use App\Events\ClientCreated;
use App\Events\ClientUpdated;
use App\Models\AutomationEvent;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the ClientObserver wiring: ClientCreated/ClientUpdated reach the
 * automation pipeline when the engine is enabled, and observers stay silent
 * when it is disabled (the default test env) so the existing suite is not
 * affected by side effects.
 */
class ClientEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_and_updating_a_client_dispatches_the_automation_events(): void
    {
        config(['automation.enabled' => true]);

        $tenant = Tenant::factory()->create();
        Tenant::setCurrent($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user, 'sanctum');

        $client = Client::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertSame(1, AutomationEvent::where('event_class', ClientCreated::class)->count());
        $this->assertSame('done', AutomationEvent::where('event_class', ClientCreated::class)->first()->status);
        $this->assertSame('client_created', AutomationEvent::where('event_class', ClientCreated::class)->first()->type);

        $client->update(['phone' => '0712 000 000']);

        $this->assertSame(1, AutomationEvent::where('event_class', ClientUpdated::class)->count());
        $this->assertSame(2, AutomationEvent::count());
    }

    #[Test]
    public function observers_are_silent_when_automation_is_disabled(): void
    {
        // phpunit.xml sets AUTOMATION_ENABLED=false — observers must no-op.
        $tenant = Tenant::factory()->create();
        Tenant::setCurrent($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user, 'sanctum');

        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $client->update(['phone' => '0700 111 222']);

        $this->assertSame(0, AutomationEvent::count());
    }
}
