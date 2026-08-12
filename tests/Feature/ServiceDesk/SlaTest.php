<?php

namespace Tests\Feature\ServiceDesk;

use App\Models\Client;
use App\Models\SlaPolicy;
use App\Models\SlaRule;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketEscalation;
use App\Models\User;
use App\Services\Support\SlaService;
use App\Services\Support\TicketService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SlaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Tenant::setCurrent($this->tenant);
    }

    private function policy(array $overrides = []): SlaPolicy
    {
        return SlaPolicy::create(array_merge([
            'tenant_id'                => $this->tenant->id,
            'name'                     => 'High priority SLA',
            'priority_level'           => 2,
            'response_time_minutes'    => 5,
            'resolution_time_minutes'  => 30,
            'escalation_enabled'       => true,
            'escalation_after_minutes' => 15,
            'is_active'                => true,
        ], $overrides));
    }

    private function openTicket(string $priority = 'high', array $overrides = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'priority'  => $priority,
            'status'    => 'open',
        ], $overrides));
    }

    public function test_apply_policy_matches_priority_and_sets_targets(): void
    {
        $this->policy();

        $ticket = app(SlaService::class)->applyPolicy($this->openTicket('high')->fresh());

        $this->assertNotNull($ticket->sla_policy_id);
        $this->assertNotNull($ticket->sla_response_due_at);
        $this->assertNotNull($ticket->sla_resolution_due_at);
        $this->assertTrue($ticket->sla_response_due_at->greaterThan(now()));
        $this->assertTrue($ticket->sla_resolution_due_at->greaterThan($ticket->sla_response_due_at));
    }

    public function test_resolution_breach_flags_and_escalates_after_window(): void
    {
        $this->policy(); // resolution target 30m

        $ticket = app(SlaService::class)->applyPolicy($this->openTicket('high'));

        $this->travelTo(now()->addMinutes(40));

        $result = app(SlaService::class)->evaluate();

        $ticket->refresh();
        $this->assertTrue($ticket->sla_breached);
        $this->assertSame(1, $result['breaches']);
        $this->assertSame(1, $result['escalations']);

        $escalation = TicketEscalation::where('ticket_id', $ticket->id)->first();
        $this->assertNotNull($escalation);
        $this->assertSame('sla_breach', $escalation->trigger);
        $this->assertSame(1, $escalation->escalation_level);
    }

    public function test_no_breach_before_resolution_window(): void
    {
        $this->policy();

        $ticket = app(SlaService::class)->applyPolicy($this->openTicket('high'));

        $this->travelTo(now()->addMinutes(10)); // within 30m resolution, past 5m response

        $result = app(SlaService::class)->evaluate();

        $this->assertSame(0, $result['breaches']);
        $this->assertSame(1, $result['escalations']); // response overdue -> escalates
        $this->assertFalse($ticket->fresh()->sla_breached);
    }

    public function test_reply_records_first_response_meeting_response_sla(): void
    {
        Queue::fake();
        $this->policy(['response_time_minutes' => 5, 'resolution_time_minutes' => 60]);

        $ticket = app(SlaService::class)->applyPolicy($this->openTicket('high'));

        app(TicketService::class)->replyTicket(
            $ticket,
            ['message' => 'Investigating your issue', 'is_internal' => false],
            $this->user->id
        );

        $this->assertNotNull($ticket->fresh()->first_responded_at);

        $this->travelTo(now()->addMinutes(10)); // past response window but responded promptly
        $result = app(SlaService::class)->evaluate();

        $this->assertSame(0, $result['escalations']);
    }

    public function test_priority_match_rule_escalates_high_tickets(): void
    {
        $policy = $this->policy();
        SlaRule::create([
            'tenant_id'      => $this->tenant->id,
            'sla_policy_id'  => $policy->id,
            'name'           => 'Escalate high/critical tickets',
            'condition_type' => 'priority_match',
            'conditions'     => ['priority_level' => 2],
            'actions'        => ['escalate' => true],
            'is_active'      => true,
        ]);

        $ticket = $this->openTicket('critical'); // level 3 >= 2
        app(SlaService::class)->applyPolicy($ticket);
        app(SlaService::class)->evaluate();

        $this->assertDatabaseHas('ticket_escalations', [
            'ticket_id'        => $ticket->id,
            'trigger'          => 'rule_match',
            'escalation_level' => 1,
        ]);
    }

    public function test_escalation_level_increments_after_previous_resolved(): void
    {
        $this->policy();

        $ticket = app(SlaService::class)->applyPolicy($this->openTicket('high'));

        $this->travelTo(now()->addMinutes(40));
        app(SlaService::class)->evaluate();
        $this->assertSame(1, (int) TicketEscalation::where('ticket_id', $ticket->id)->max('escalation_level'));

        // Close the level-1 escalation so the next run escalates to level 2
        TicketEscalation::where('ticket_id', $ticket->id)
            ->update(['resolved_at' => now(), 'resolved_by' => $this->user->id]);

        $this->travelTo(now()->addMinutes(60));
        app(SlaService::class)->evaluate();

        $this->assertSame(2, (int) TicketEscalation::where('ticket_id', $ticket->id)->max('escalation_level'));
    }

    public function test_ticket_service_apply_policy_on_create(): void
    {
        $this->policy();

        $ticket = app(TicketService::class)->createTicket([
            'client_id'   => $this->client->id,
            'subject'     => 'Billing question',
            'description' => 'Invoice discrepancy on last cycle.',
            'priority'    => 'high',
        ], $this->user->id);

        $this->assertSame('open', $ticket->status);
        $this->assertNotNull($ticket->sla_policy_id);
        $this->assertNotNull($ticket->sla_resolution_due_at);
    }

    public function test_sla_evaluate_command_runs_and_reports(): void
    {
        $this->policy();

        $ticket = app(SlaService::class)->applyPolicy($this->openTicket('high'));

        $this->travelTo(now()->addMinutes(45));

        $this->artisan('sla:evaluate')->assertSuccessful();

        $this->assertDatabaseHas('ticket_escalations', ['ticket_id' => $ticket->id]);
    }
}