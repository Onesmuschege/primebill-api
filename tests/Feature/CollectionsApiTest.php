<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use App\Models\Client;
use App\Models\DunningRun;
use App\Models\DunningStep;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Exercises the Collections/Dunning REST API introduced to expose the
 * existing DunningService engine (aging dashboard, step-ladder CRUD/reorder,
 * manual run-now with idempotency, run history) with proper authorization and
 * tenant isolation.
 */
class CollectionsApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo(['view collections', 'manage dunning']);
    }

    private function authAsAdmin(): void
    {
        $this->actingAs($this->user, 'sanctum');
    }

    private function makeStep(string $name, int $seq, string $action, int $days, bool $active = true, ?Tenant $t = null): DunningStep
    {
        return DunningStep::create([
            'tenant_id'      => ($t ?? $this->tenant)->id,
            'name'           => $name,
            'sequence'       => $seq,
            'action'         => $action,
            'days_after_due' => $days,
            'is_active'      => $active,
        ]);
    }

    private function makeOverdueInvoice(Client $client, string $number, int $daysAgo, float $total = 1500): Invoice
    {
        // tenant_id is deliberately NOT mass-assignable on Invoice (same rule
        // as User): it is populated by the BelongsToTenant creating hook from
        // Tenant::current(), so bind the tenant explicitly before persisting.
        Tenant::setCurrent($this->tenant);

        return Invoice::create([
            'client_id'      => $client->id,
            'invoice_number' => $number,
            'amount'         => $total,
            'tax'            => 0,
            'total'          => $total,
            'status'         => 'overdue',
            'due_date'       => now()->subDays($daysAgo),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Aging dashboard
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function aging_dashboard_buckets_overdue_invoices(): void
    {
        $this->authAsAdmin();

        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->makeOverdueInvoice($client, 'INV-AGING-5D', 5);

        $response = $this->getJson('/api/collections/aging');
        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, $response->json('data.total_invoices'));
        $this->assertEquals(1500, $response->json('data.total_outstanding'));
        $this->assertSame('0-30 days', $response->json('data.buckets.0.label'));
        $this->assertSame(1, $response->json('data.buckets.0.count'));
        $this->assertSame(1, $response->json('data.clients.0.invoice_count'));
    }

    #[Test]
    public function aging_dashboard_is_tenant_scoped(): void
    {
        $this->authAsAdmin();

        // Another tenant's overdue invoice must not leak into this tenant's view.
        $other = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($other);
        $otherClient = Client::factory()->create(['tenant_id' => $other->id]);
        Invoice::create([
            'client_id'      => $otherClient->id,
            'tenant_id'      => $other->id,
            'invoice_number' => 'INV-AGING-OTHER',
            'amount'         => 9999,
            'tax'            => 0,
            'total'          => 9999,
            'status'         => 'overdue',
            'due_date'       => now()->subDays(10),
        ]);
        Tenant::setCurrent($this->tenant);

        $response = $this->getJson('/api/collections/aging');
        $response->assertOk();
        $this->assertSame(0, $response->json('data.total_invoices'));
    }

    // ─────────────────────────────────────────────────────────────
    // Dunning step ladder CRUD
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function can_create_list_update_and_delete_a_dunning_step(): void
    {
        $this->authAsAdmin();

        $created = $this->postJson('/api/collections/dunning-steps', [
            'name'           => 'Email 3d',
            'sequence'       => 1,
            'action'         => 'email',
            'days_after_due' => 3,
            'is_active'      => true,
        ]);
        $created->assertStatus(201)->assertJson(['success' => true]);
        $stepId = $created->json('data.id');
        $this->assertDatabaseHas('dunning_steps', ['id' => $stepId, 'tenant_id' => $this->tenant->id]);

        $this->getJson('/api/collections/dunning-steps')
            ->assertOk()
            ->assertJsonPath('data.0.id', $stepId);

        $this->getJson("/api/collections/dunning-steps/{$stepId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Email 3d');

        $this->putJson("/api/collections/dunning-steps/{$stepId}", ['days_after_due' => 7])
            ->assertOk()
            ->assertJsonPath('data.days_after_due', 7);

        $this->deleteJson("/api/collections/dunning-steps/{$stepId}")
            ->assertOk()
            ->assertJson(['message' => 'Dunning step removed']);
        $this->assertDatabaseMissing('dunning_steps', ['id' => $stepId]);
    }

    #[Test]
    public function step_validation_rejects_unknown_action(): void
    {
        $this->authAsAdmin();

        $this->postJson('/api/collections/dunning-steps', [
            'name'           => 'Bad',
            'sequence'       => 1,
            'action'         => 'telepathy',
            'days_after_due' => 3,
        ])->assertStatus(422);
    }

    #[Test]
    public function can_reorder_the_step_ladder(): void
    {
        $this->authAsAdmin();

        $a = $this->makeStep('Step A', 1, 'email', 3);
        $b = $this->makeStep('Step B', 2, 'sms', 7);

        $this->postJson('/api/collections/dunning-steps/reorder', [
            'steps' => [
                ['id' => $b->id, 'sequence' => 1],
                ['id' => $a->id, 'sequence' => 2],
            ],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(2, $a->fresh()->sequence);
        $this->assertSame(1, $b->fresh()->sequence);
    }

    // ─────────────────────────────────────────────────────────────
    // Dunning execution (manual run now)
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function run_now_executes_the_email_step_and_is_idempotent(): void
    {
        Mail::fake();
        Queue::fake();
        $this->authAsAdmin();

        $this->makeStep('Email 3d', 1, 'email', 3);
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->makeOverdueInvoice($client, 'INV-RUN-5D', 5);

        $first = $this->postJson('/api/collections/run');
        $first->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $first->json('data.email'));

        // A second run must not re-send (idempotency via dunning_runs).
        $second = $this->postJson('/api/collections/run');
        $second->assertOk();
        $this->assertSame(0, $second->json('data.email'));
        $this->assertSame(1, DunningRun::count());
    }

    #[Test]
    public function run_history_is_listable_and_tenant_scoped(): void
    {
        Mail::fake();
        Queue::fake();
        $this->authAsAdmin();

        $this->makeStep('Email 3d', 1, 'email', 3);
        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = $this->makeOverdueInvoice($client, 'INV-RUNS-5D', 5);

        $this->postJson('/api/collections/run')->assertOk();

        $this->getJson('/api/collections/dunning-runs')
            ->assertOk()
            ->assertJsonPath('data.data.0.invoice_id', $invoice->id)
            ->assertJsonPath('data.data.0.status', 'sent');

        // Per-client filter
        $this->getJson("/api/collections/clients/{$client->id}/dunning-runs")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Per-invoice filter
        $this->getJson("/api/collections/invoices/{$invoice->id}/dunning-runs")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ─────────────────────────────────────────────────────────────
    // Authorization & isolation
    // ─────────────────────────────────────────────────────────────

    #[Test]
    public function staff_with_view_only_cannot_mutate_the_ladder(): void
    {
        $viewOnly = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $viewOnly->givePermissionTo(['view collections']); // no manage dunning
        $this->actingAs($viewOnly, 'sanctum');

        $this->postJson('/api/collections/dunning-steps', [
            'name' => 'Denied', 'sequence' => 1, 'action' => 'email', 'days_after_due' => 3,
        ])->assertForbidden();

        $this->postJson('/api/collections/run')->assertForbidden();

        // Read endpoints remain accessible
        $this->getJson('/api/collections/aging')->assertOk();
    }

    #[Test]
    public function unauthenticated_requests_are_blocked(): void
    {
        $this->getJson('/api/collections/aging')->assertUnauthorized();
        $this->postJson('/api/collections/run')->assertUnauthorized();
    }

    #[Test]
    public function another_tenants_step_is_not_visible_and_404s_on_show(): void
    {
        $this->authAsAdmin();

        $other = Tenant::factory()->create();
        $otherStep = $this->makeStep('B-only', 1, 'email', 3, true, $other);

        $this->getJson('/api/collections/dunning-steps')
            ->assertOk()
            ->assertJsonMissing(['sequence' => 1, 'name' => 'B-only']);

        $this->getJson("/api/collections/dunning-steps/{$otherStep->id}")->assertNotFound();
    }
}