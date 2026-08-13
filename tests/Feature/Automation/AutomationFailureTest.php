<?php

namespace Tests\Feature\Automation;

use App\Events\PaymentReceived;
use App\Models\AutomationEvent;
use App\Models\AutomationFailure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\Automation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationFailureTest extends TestCase
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

    public function test_a_failing_step_is_recorded_as_a_failure(): void
    {
        $event = new PaymentReceived(null, ['entity_id' => 9, 'force_failure' => true]);
        $key = $event->idempotencyKey();

        try {
            event($event);
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced failure for automation test', $e->getMessage());
        }

        $record = AutomationEvent::where('idempotency_key', $key)->first();
        $this->assertNotNull($record);
        $this->assertSame('failed', $record->status);
        $this->assertSame(1, AutomationFailure::count());
        $this->assertSame('payment_received-listener', AutomationFailure::first()->job_class);
    }

    public function test_a_failed_event_can_be_retried_and_completes(): void
    {
        $event = new PaymentReceived(null, ['entity_id' => 11, 'force_failure' => true]);
        $key = $event->idempotencyKey();

        try {
            event($event);
        } catch (\Throwable $e) {
            // expected
        }

        $failure = AutomationFailure::firstOrFail();
        $ok = app(Automation::class)->retry($failure);
        $this->assertTrue($ok);

        $this->assertNotNull($failure->fresh()->resolved_at);
        $this->assertSame(2, AutomationEvent::where('entity_id', 11)->count());
        $this->assertDatabaseHas('automation_events', ['idempotency_key' => $key, 'status' => 'failed']);
        $done = AutomationEvent::where('status', 'done')->first();
        $this->assertNotNull($done);
    }
}
