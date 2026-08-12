<?php

namespace Tests\Feature\Inventory;

use App\Models\Rma;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RmaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo([
            'view rmas',
            'create rmas',
            'edit rmas',
            'delete rmas',
        ]);

        // NOTE: authentication is established per-test so we can also assert
        // the unauthenticated (401) and insufficient-permission (403) paths.
    }

    private function seedRma(): array
    {
        $this->actingAs($this->user, 'sanctum');

        $created = $this->postJson('/api/rma', [
            'type'     => 'replacement',
            'priority' => 'high',
            'reason'   => 'Defective unit reported on site #12.',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('rmas', [
            'id'           => $created['id'],
            'rma_number'   => $created['rma_number'],
            'status'       => Rma::STATUS_REQUESTED,
            'type'         => Rma::TYPE_REPLACEMENT,
            'priority'     => Rma::PRIORITY_HIGH,
            'requested_by' => $this->user->id,
        ]);

        return $created;
    }

    public function test_full_lifecycle_requested_to_completed(): void
    {
        $rma = $this->seedRma();
        $id  = $rma['id'];

        // requested -> approved
        $this->postJson("/api/rma/{$id}/approve", ['notes' => 'Supplier stock confirmed.'])
            ->assertOk()
            ->assertJson(['status' => Rma::STATUS_APPROVED]);
        $this->assertDatabaseHas('rmas', ['id' => $id, 'status' => 'approved', 'approved_by' => $this->user->id]);

        // approved -> processing
        $this->postJson("/api/rma/{$id}/process", ['tracking_number' => 'TN-123456'])
            ->assertOk()
            ->assertJson(['status' => Rma::STATUS_PROCESSING]);
        $this->assertDatabaseHas('rmas', ['id' => $id, 'status' => 'processing', 'tracking_number' => 'TN-123456']);

        // processing -> completed
        $this->postJson("/api/rma/{$id}/complete")
            ->assertOk()
            ->assertJson(['status' => Rma::STATUS_COMPLETED]);
        $this->assertDatabaseHas('rmas', ['id' => $id, 'status' => 'completed', 'resolved_by' => $this->user->id]);
        $this->assertNotNull($this->app->make('db')->table('rmas')->where('id', $id)->value('completed_at'));

        // Audit trail persisted by LogsAudit (SystemLog columns: model / model_id / action)
        $this->assertDatabaseHas('system_logs', [
            'model'    => 'Rma',
            'model_id' => $id,
            'action'   => 'Rma.created',
        ]);
    }

    public function test_rma_can_be_rejected_from_requested(): void
    {
        $rma = $this->seedRma();

        $this->postJson("/api/rma/{$rma['id']}/reject", ['reason' => 'Unit still under warranty.'])
            ->assertOk()
            ->assertJson(['status' => Rma::STATUS_REJECTED]);

        $this->assertDatabaseHas('rmas', [
            'id'          => $rma['id'],
            'status'      => 'rejected',
            'resolved_by' => $this->user->id,
        ]);
    }

    public function test_state_guard_prevents_processing_before_approval(): void
    {
        $rma = $this->seedRma(); // status = requested

        $this->postJson("/api/rma/{$rma['id']}/process")
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('rmas', ['id' => $rma['id'], 'status' => 'requested']);
    }

    public function test_state_guard_prevents_completing_without_processing(): void
    {
        $rma = $this->seedRma(); // requested
        $this->postJson("/api/rma/{$rma['id']}/approve")->assertOk();

        // cannot complete from approved (must pass through processing)
        $this->postJson("/api/rma/{$rma['id']}/complete")
            ->assertStatus(422);

        $this->assertDatabaseHas('rmas', ['id' => $rma['id'], 'status' => 'approved']);
    }

    public function test_state_guard_allows_cancellation_from_requested(): void
    {
        $rma = $this->seedRma();

        $this->postJson("/api/rma/{$rma['id']}/cancel", ['reason' => 'Customer withdrew request.'])
            ->assertOk()
            ->assertJson(['status' => Rma::STATUS_CANCELLED]);

        $this->assertDatabaseHas('rmas', ['id' => $rma['id'], 'status' => 'cancelled']);

                // terminal state: cannot approve a cancelled RMA
        $this->postJson("/api/rma/{$rma['id']}/approve")
            ->assertStatus(422);
    }

    public function test_stats_endpoint_aggregates_lifecycle(): void
    {
        $this->seedRma(); // requested
        $rma2 = $this->seedRma();
        $this->postJson("/api/rma/{$rma2['id']}/approve")->assertOk();
        $this->postJson("/api/rma/{$rma2['id']}/process")->assertOk();
        $this->postJson("/api/rma/{$rma2['id']}/complete")->assertOk();

        $stats = $this->getJson('/api/rma/stats')->assertOk()->json();

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['open']);
        $this->assertSame(1, $stats['by_status']['completed']);
        $this->assertSame(1, $stats['by_status']['requested']);
    }

    public function test_unauthenticated_user_cannot_access_rma_api(): void
    {
        // No actingAs() call, so the request is a guest; the auth:sanctum
        // guard on the /rma route group returns 401 before the permission check.
        $this->postJson('/api/rma', ['type' => 'replacement'])
            ->assertStatus(401);
    }

    public function test_user_without_rma_permissions_is_forbidden(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($other, 'sanctum');

        $this->getJson('/api/rma')->assertStatus(403);
        $this->postJson('/api/rma')->assertStatus(403);
    }
}
