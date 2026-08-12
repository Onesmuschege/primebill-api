<?php

namespace Tests\Feature\FieldOperations;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderMaterialsEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo(['view work-orders', 'create work-orders', 'edit work-orders']);

        Tenant::setCurrent($this->tenant);
        $this->actingAs($this->user, 'sanctum');

        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->workOrder = WorkOrder::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
            'status'    => 'in_progress',
        ]);
    }

    public function test_add_part_and_list_materials(): void
    {
        $res = $this->postJson("/api/work-orders/{$this->workOrder->id}/parts", [
            'part_name'   => 'RJ45 Connector',
            'part_number' => 'RJ45-CAT6',
            'quantity'    => 10,
            'unit_cost'   => 1.5,
            'status'      => 'received',
            'notes'       => 'Bulk pack',
        ])->assertCreated();

        $this->assertSame('RJ45 Connector', $res->json('data.part_name'));
        $this->assertEquals(15.0, (float) $res->json('data.total_cost'));

        $this->assertDatabaseHas('work_order_parts', [
            'work_order_id' => $this->workOrder->id,
            'part_name'     => 'RJ45 Connector',
            'status'        => 'received',
        ]);

        $list = $this->getJson("/api/work-orders/{$this->workOrder->id}/parts")->assertOk();
        $this->assertCount(1, $list->json('data'));
    }

    public function test_part_validation_rejects_unknown_status(): void
    {
        $this->postJson("/api/work-orders/{$this->workOrder->id}/parts", [
            'part_name' => 'Adapter',
            'status'    => 'bogus',
        ])->assertStatus(422);
    }

    public function test_add_attachment_evidence_and_list(): void
    {
        $res = $this->postJson("/api/work-orders/{$this->workOrder->id}/attachments", [
            'file_name'   => 'before-panel.jpg',
            'file_path'   => 'uploads/evidence/before-panel.jpg',
            'file_type'   => 'image/jpeg',
            'category'    => 'photo',
            'description' => 'Distribution point before work',
        ])->assertCreated();

        $this->assertSame('photo', $res->json('data.category'));
        $this->assertSame($this->user->id, $res->json('data.uploaded_by'));

        $this->assertDatabaseHas('work_order_attachments', [
            'work_order_id' => $this->workOrder->id,
            'category'      => 'photo',
        ]);

        $list = $this->getJson("/api/work-orders/{$this->workOrder->id}/attachments")->assertOk();
        $this->assertCount(1, $list->json('data'));
    }
}