<?php

namespace Tests\Feature\Automation;

use App\Events\ClientCreated;
use App\Events\ClientUpdated;
use App\Events\InvoiceGenerated;
use App\Events\InvoiceOverdue;
use App\Events\OLTOffline;
use App\Events\PaymentFailed;
use App\Events\PaymentReceived;
use App\Events\RouterOffline;
use App\Events\SLABreached;
use App\Events\SubscriptionActivated;
use App\Events\SubscriptionSuspended;
use App\Events\SubscriptionTerminated;
use App\Events\TicketCreated;
use App\Events\WorkOrderCompleted;
use App\Listeners\AutomationListener;
use App\Models\AutomationEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\Automation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user, 'sanctum');
        config(['automation.enabled' => true]);
    }

    public function test_every_automation_event_is_recorded_and_marked_done(): void
    {
        $events = [
            ClientCreated::class, ClientUpdated::class,
            SubscriptionActivated::class, SubscriptionSuspended::class, SubscriptionTerminated::class,
            InvoiceGenerated::class, InvoiceOverdue::class,
            PaymentReceived::class, PaymentFailed::class,
            RouterOffline::class, OLTOffline::class,
            TicketCreated::class, SLABreached::class, WorkOrderCompleted::class,
        ];

        foreach ($events as $i => $class) {
            event(new $class(null, ['entity_id' => $i + 1]));
        }

        $this->assertSame(14, AutomationEvent::count());
        foreach (AutomationEvent::all() as $event) {
            $this->assertSame('done', $event->status);
        }
        $this->assertSame('payment_received', AutomationEvent::type('payment_received')->first()->type);
    }

    public function test_payment_received_is_idempotent(): void
    {
        $event = new PaymentReceived(null, ['entity_id' => 42]);
        $key = $event->idempotencyKey();

        event(new PaymentReceived(null, ['entity_id' => 42]));
        event(new PaymentReceived(null, ['entity_id' => 42]));

        $this->assertSame(1, AutomationEvent::where('idempotency_key', $key)->count());
        $this->assertSame('done', AutomationEvent::where('idempotency_key', $key)->first()->status);
    }

    public function test_disabled_automation_records_nothing(): void
    {
        config(['automation.enabled' => false]);

        event(new PaymentReceived(null, ['entity_id' => 1]));

        $this->assertSame(0, AutomationEvent::count());
    }

    public function test_listener_is_queued_with_retry_and_timeout_config(): void
    {
        $listener = new AutomationListener(app(Automation::class));

        $this->assertInstanceOf(ShouldQueue::class, $listener);
        $this->assertSame(config('automation.retry.tries'), $listener->tries);
        $this->assertSame(config('automation.retry.timeout'), $listener->timeout);
        $this->assertSame(config('automation.retry.backoff'), $listener->backoff);
    }
}
