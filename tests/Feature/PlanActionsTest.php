<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user  = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    public function test_admin_can_duplicate_a_plan(): void
    {
        $plan = Plan::factory()->create([
            'name'         => 'Home Bronze 10Mbps',
            'type'         => 'pppoe',
            'price'        => 1500,
            'speed_down'   => 10240,
            'speed_up'     => 2048,
            'is_active'    => true,
            'validity_days'=> 30,
        ]);

        $response = $this->postJson("/api/plans/{$plan->id}/duplicate", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Home Bronze 10Mbps (Copy)')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.price', 1500);

        $this->assertDatabaseHas('plans', ['name' => 'Home Bronze 10Mbps (Copy)']);
    }

    public function test_admin_can_toggle_plan_active_state(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        $response = $this->postJson("/api/plans/{$plan->id}/toggle-active", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', false);

        $response = $this->postJson("/api/plans/{$plan->id}/toggle-active", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertJsonPath('data.is_active', true);
    }

    public function test_admin_can_bulk_update_plan_bandwidth(): void
    {
        $p1 = Plan::factory()->create(['name' => 'Plan A', 'speed_down' => 10240]);
        $p2 = Plan::factory()->create(['name' => 'Plan B', 'speed_down' => 5120]);

        $response = $this->postJson('/api/plans/bulk/update', [
            'ids'       => [$p1->id, $p2->id],
            'speed_up'  => 4096,
            'speed_down'=> 20480,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated', 2);

        $this->assertEquals(20480, $p1->fresh()->speed_down);
        $this->assertEquals(20480, $p2->fresh()->speed_down);
        $this->assertEquals(4096, $p1->fresh()->speed_up);
    }

    public function test_bulk_update_rejects_unknown_ids(): void
    {
        $response = $this->postJson('/api/plans/bulk/update', [
            'ids'         => [999999],
            'speed_down'  => 10240,
        ], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422);
    }

    public function test_push_to_router_without_assigned_router_returns_422(): void
    {
        $plan = Plan::factory()->create(['router_id' => null]);

        $response = $this->postJson("/api/plans/{$plan->id}/push-to-router", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_unprivileged_user_cannot_duplicate_plan(): void
    {
        $plan = Plan::factory()->create();

        $worker = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $worker->assignRole('staff');
        $workerToken = $worker->createToken('test-token')->plainTextToken;

        $response = $this->postJson("/api/plans/{$plan->id}/duplicate", [], [
            'Authorization' => "Bearer {$workerToken}",
        ]);

        $response->assertStatus(403);
    }
}