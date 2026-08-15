<?php

namespace Tests\Feature\FieldOperations;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class WorkOrderVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $ops;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'FieldOps Tenant',
            'slug' => 'fieldops-tenant',
        ]);

        $this->ops = User::create([
            'name' => 'Ops Lead',
            'email' => 'ops-lead@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $role = Role::findOrCreate('field-ops');
        foreach (['view work-orders', 'create work-orders', 'edit work-orders'] as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        $role->givePermissionTo(['view work-orders', 'create work-orders', 'edit work-orders']);
        $this->ops->assignRole($role);

        $this->client = Client::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '+254700000001',
        ]);
    }

    private function workOrder(string $status): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'work_order_number' => 'WO-VERIFY-' . uniqid(),
            'type' => 'installation',
            'status' => $status,
            'priority' => 'high',
            'description' => 'Install FTTH drop',
            'created_by' => $this->ops->id,
        ]);
    }

    #[Test]
    public function completed_work_order_can_be_verified()
    {
        Sanctum::actingAs($this->ops);

        $workOrder = $this->workOrder('completed');

        $response = $this->postJson("/api/work-orders/{$workOrder->id}/verify", [
            'verification_notes' => 'Signal levels within spec, customer confirmed.',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Work order verified']);

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'verified_by' => $this->ops->id,
            'verification_notes' => 'Signal levels within spec, customer confirmed.',
        ]);

        $this->assertNotNull($response->json('data.verified_at'));
    }

    #[Test]
    public function non_completed_work_order_cannot_be_verified()
    {
        Sanctum::actingAs($this->ops);

        $workOrder = $this->workOrder('in_progress');

        $this->postJson("/api/work-orders/{$workOrder->id}/verify", [
            'verification_notes' => 'premature',
        ])->assertStatus(422);

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'verified_by' => null,
            'verified_at' => null,
        ]);
    }

    #[Test]
    public function status_history_timeline_is_returned()
    {
        Sanctum::actingAs($this->ops);

        $workOrder = $this->workOrder('completed');

        WorkOrderStatusHistory::create([
            'tenant_id' => $this->tenant->id,
            'work_order_id' => $workOrder->id,
            'from_status' => 'scheduled',
            'to_status' => 'dispatched',
            'changed_by' => $this->ops->id,
        ]);
        WorkOrderStatusHistory::create([
            'tenant_id' => $this->tenant->id,
            'work_order_id' => $workOrder->id,
            'from_status' => 'dispatched',
            'to_status' => 'completed',
            'changed_by' => $this->ops->id,
        ]);

        $response = $this->getJson("/api/work-orders/{$workOrder->id}/status-history");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $history = $response->json('data');
        $this->assertCount(2, $history);
        $this->assertEquals('completed', $history[0]['to_status']);
    }
}
