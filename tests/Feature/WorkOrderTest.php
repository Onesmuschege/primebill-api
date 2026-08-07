<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        // Grant necessary permissions for work orders
        $this->user->givePermissionTo([
            'view clients',
            'view work-orders',
            'create work-orders',
            'edit work-orders',
            'delete work-orders',
        ]);
    }

    public function test_user_can_create_work_order(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson("/api/clients/{$this->client->id}/work-orders", [
            'type' => 'installation',
            'priority' => 'high',
            'description' => 'New fiber installation for client',
            'notes' => 'Client requested morning appointment',
            'scheduled_at' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Work order created successfully',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'work_order_number',
                    'type',
                    'priority',
                    'description',
                    'client' => [
                        'id',
                        'first_name',
                        'last_name',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('work_orders', [
            'client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'type' => 'installation',
            'priority' => 'high',
            'status' => 'scheduled',
        ]);
    }

    public function test_user_can_assign_technician_to_work_order(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $technician = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $workOrder = WorkOrder::factory()->create([
            'client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => 'scheduled',
        ]);

        $response = $this->postJson("/api/work-orders/{$workOrder->id}/assign", [
            'assigned_to' => $technician->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Technician assigned successfully',
            ]);

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'assigned_to' => $technician->id,
            'status' => 'dispatched',
        ]);
    }

    public function test_user_can_update_work_order_status(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $workOrder = WorkOrder::factory()->create([
            'client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => 'scheduled',
        ]);

        $response = $this->postJson("/api/work-orders/{$workOrder->id}/status", [
            'status' => 'in_progress',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Work order status updated',
            ]);

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_user_can_complete_work_order_with_signature(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $workOrder = WorkOrder::factory()->create([
            'client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => 'in_progress',
        ]);

        $response = $this->postJson("/api/work-orders/{$workOrder->id}/status", [
            'status' => 'completed',
            'completion_notes' => 'Installation completed successfully',
            'customer_signature' => ['data' => 'base64_signature_here'],
            'photos' => ['https://example.com/photo1.jpg'],
            'completion_latitude' => -1.2921,
            'completion_longitude' => 36.8219,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Work order status updated',
            ]);

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'status' => 'completed',
        ]);
    }

    public function test_user_can_view_work_order_stats(): void
    {
        $this->actingAs($this->user, 'sanctum');

        WorkOrder::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->getJson('/api/work-orders/stats');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total',
                    'scheduled',
                    'dispatched',
                    'in_progress',
                    'completed_today',
                    'cancelled',
                    'by_type',
                    'by_priority',
                ],
            ]);
    }

    public function test_technician_can_view_their_workload(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $technician = User::factory()->create(['tenant_id' => $this->tenant->id]);

        WorkOrder::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'assigned_to' => $technician->id,
            'status' => 'scheduled',
        ]);

        WorkOrder::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'assigned_to' => $technician->id,
            'status' => 'in_progress',
        ]);

        $response = $this->getJson("/api/technicians/{$technician->id}/workload");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'scheduled',
                    'in_progress',
                    'completed_today',
                    'total',
                ],
            ]);

        $this->assertEquals(3, $response->json('data.scheduled'));
        $this->assertEquals(2, $response->json('data.in_progress'));
    }
}
