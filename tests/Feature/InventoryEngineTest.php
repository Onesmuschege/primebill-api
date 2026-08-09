<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * InventoryEngineTest
 *
 * End-to-end coverage of the inventory engine workflows (Phase D2):
 *   1. Stock movements — receive / issue / adjust / return
 *   2. Negative-stock prevention
 *   3. Stock transfers — draft → approve → dispatch → receive
 *   4. Purchase orders — draft → submit → approve → partial receive → complete
 *   5. Tenant isolation across the workflows
 */
class InventoryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');

        Sanctum::actingAs($this->user);
    }

    private function makeWarehouse(string $name = 'Main WH', string $code = 'WH-01'): Warehouse
    {
        return Warehouse::create([
            'tenant_id' => $this->tenant->id,
            'name'      => $name,
            'code'      => $code,
        ]);
    }

    private function makeSupplier(string $name = 'Acme Supply'): Supplier
    {
        return Supplier::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => $name,
            'code'       => 'SUP-' . strtoupper(substr(uniqid(), -4)),
        ]);
    }

    private function makeItem(string $name = 'Router', int $qty = 0): InventoryItem
    {
        return InventoryItem::create([
            'tenant_id'  => $this->tenant->id,
            'name'       => $name,
            'category'   => 'CPE',
            'quantity'   => $qty,
            'unit_cost'  => 100,
            'status'     => $qty > 0 ? 'in_stock' : 'in_stock',
        ]);
    }

    /* ─────────────────────────── Stock movements ─────────────────────────── */

    public function test_receive_stock_increases_balance(): void
    {
        $wh   = $this->makeWarehouse();
        $item = $this->makeItem();

        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity'          => 10,
            'unit_cost'         => 150,
        ])->assertStatus(201)
            ->assertJsonPath('data.quantity_after', 10)
            ->assertJsonPath('data.quantity', 10);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity_before'   => 0,
            'quantity_after'    => 10,
        ]);
    }

    public function test_issue_stock_requires_sufficient_balance(): void
    {
        $wh   = $this->makeWarehouse();
        $item = $this->makeItem();

        // Receive 5 first.
        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity'          => 5,
        ])->assertStatus(201);

        // Issuing > balance must fail (validate 422 from ValidationException).
        $this->postJson('/api/inventory/operations/stock/issue', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity'          => 10,
        ])->assertStatus(422);
    }

    public function test_issue_stock_decreases_balance_and_tracks_history(): void
    {
        $wh   = $this->makeWarehouse();
        $item = $this->makeItem();

        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity'          => 8,
        ])->assertStatus(201);

        $this->postJson('/api/inventory/operations/stock/issue', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity'          => 3,
        ])->assertStatus(200)
            ->assertJsonPath('data.quantity_after', 5);

        $this->assertDatabaseHas('inventory_item_history', [
            'inventory_item_id' => $item->id,
            'action'            => 'issued',
            'actor_id'          => $this->user->id,
        ]);
    }

    public function test_stock_adjustment_sets_target_quantity(): void
    {
        $wh   = $this->makeWarehouse();
        $item = $this->makeItem();

        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity'          => 7,
        ])->assertStatus(201);

        $this->postJson('/api/inventory/operations/stock/adjust', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'new_quantity'      => 2,
        ])->assertStatus(200)
            ->assertJsonPath('data.quantity_after', 2);
    }

    public function test_balances_endpoint_returns_per_warehouse(): void
    {
        $wh1 = $this->makeWarehouse('WH A', 'WH-A');
        $wh2 = $this->makeWarehouse('WH B', 'WH-B');
        $item = $this->makeItem();

        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh1->id,
            'quantity'          => 4,
        ])->assertStatus(201);

        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh2->id,
            'quantity'          => 6,
        ])->assertStatus(201);

        $this->getJson("/api/inventory/operations/items/{$item->id}/balances")
            ->assertStatus(200)
            ->assertJsonPath('data.quantity', 10)
            ->assertJsonCount(2, 'data.balances');
    }

    /* ─────────────────────────── Stock transfers ─────────────────────────── */

    public function test_transfer_full_lifecycle_moves_stock(): void
    {
        $src = $this->makeWarehouse('Source', 'SRC');
        $dst = $this->makeWarehouse('Dest', 'DST');
        $item = $this->makeItem();

        // Seed source stock.
        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $src->id,
            'quantity'          => 20,
        ])->assertStatus(201);

        // Draft
        $store = $this->postJson('/api/inventory/operations/transfers', [
            'source_warehouse_id'      => $src->id,
            'destination_warehouse_id' => $dst->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity' => 5],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $transferId = $store->json('data.id');

        // approve
        $this->postJson("/api/inventory/operations/transfers/{$transferId}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // dispatch → reduces source
        $this->postJson("/api/inventory/operations/transfers/{$transferId}/dispatch")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'dispatched');

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $src->id,
            'reference_type'    => 'stock_transfer',
            'quantity'          => -5,
        ]);

        // receive → increases destination
        $this->postJson("/api/inventory/operations/transfers/{$transferId}/receive")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseHas('stock_movements', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $dst->id,
            'reference_type'    => 'stock_transfer',
            'quantity'          => 5,
        ]);

        // Source should now hold 15, destination 5.
        $balances = $this->getJson("/api/inventory/operations/items/{$item->id}/balances")
            ->json('data.balances');

        $byName = collect($balances)->keyBy('warehouse');
        $this->assertEquals(15, $byName['Source']['quantity']);
        $this->assertEquals(5, $byName['Dest']['quantity']);
    }

    public function test_transfer_cannot_be_received_twice(): void
    {
        $src   = $this->makeWarehouse('Source', 'SRC');
        $dst   = $this->makeWarehouse('Dest', 'DST');
        $item  = $this->makeItem();

        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $src->id,
            'quantity'          => 10,
        ])->assertStatus(201);

        $transferId = $this->postJson('/api/inventory/operations/transfers', [
            'source_warehouse_id'      => $src->id,
            'destination_warehouse_id' => $dst->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity' => 4]],
        ])->json('data.id');

        $this->postJson("/api/inventory/operations/transfers/{$transferId}/approve")->assertStatus(200);
        $this->postJson("/api/inventory/operations/transfers/{$transferId}/dispatch")->assertStatus(200);
        $this->postJson("/api/inventory/operations/transfers/{$transferId}/receive")->assertStatus(200);

        // Receiving again must fail with 422.
        $this->postJson("/api/inventory/operations/transfers/{$transferId}/receive")->assertStatus(422);
    }

    public function test_transfer_cannot_approve_without_sufficient_source_stock(): void
    {
        $src = $this->makeWarehouse('Source', 'SRC');
        $dst = $this->makeWarehouse('Dest', 'DST');
        $item = $this->makeItem();

        // Note: no stock received at source.
        $transferId = $this->postJson('/api/inventory/operations/transfers', [
            'source_warehouse_id'      => $src->id,
            'destination_warehouse_id' => $dst->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity' => 4]],
        ])->json('data.id');

        $this->postJson("/api/inventory/operations/transfers/{$transferId}/approve")->assertStatus(422);
    }

    public function test_empty_stock_mutation_forbids_cross_tenant(): void
    {
        $wh   = $this->makeWarehouse();
        $item = $this->makeItem();

        $other = Tenant::factory()->create(['status' => 'active']);
        $intruder = User::factory()->create(['tenant_id' => $other->id]);
        $intruder->assignRole('super_admin');
        Tenant::setCurrent($other);
        Sanctum::actingAs($intruder);

        // Cross-tenant receive attempt on our item/warehouse must fail (scoped).
        $this->postJson('/api/inventory/operations/stock/receive', [
            'inventory_item_id' => $item->id,
            'warehouse_id'      => $wh->id,
            'quantity'          => 5,
        ])->assertStatus(422);
    }

    /* ─────────────────────────── Purchase orders ─────────────────────────── */

    public function test_purchase_order_partial_receive_to_complete(): void
    {
        $supplier = $this->makeSupplier();
        $wh       = $this->makeWarehouse();
        $item     = $this->makeItem();

        $po = $this->postJson('/api/inventory/operations/purchase-orders', [
            'supplier_id'  => $supplier->id,
            'warehouse_id' => $wh->id,
            'items' => [
                ['inventory_item_id' => $item->id, 'quantity' => 10, 'unit_cost' => 50],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        $poItemId = $po['items'][0]['id'];

        // Submit + approve
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/submit")->assertStatus(200);
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Partial receive of 4 → partially_received
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $poItemId, 'quantity' => 4]],
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'partially_received');

        // Receiving more than remaining must fail.
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $poItemId, 'quantity' => 10]],
        ])->assertStatus(422);

        // Complete the remaining 6 → received
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/receive", [
            'items' => [['purchase_order_item_id' => $poItemId, 'quantity' => 6]],
        ])->assertStatus(200)
            ->assertJsonPath('data.status', 'received');

        // Complete
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        // Stock received at the WH should total 10 (4 + 6 across the two
        // partial receives).
        $received = \App\Models\StockMovement::where('inventory_item_id', $item->id)
            ->where('warehouse_id', $wh->id)
            ->where('reference_type', 'purchase_order')
            ->sum('quantity');

        $this->assertEquals(10, $received);
    }

    public function test_purchase_order_cancel_only_from_draft_or_submitted(): void
    {
        $supplier = $this->makeSupplier();
        $wh       = $this->makeWarehouse();
        $item     = $this->makeItem();

        $po = $this->postJson('/api/inventory/operations/purchase-orders', [
            'supplier_id'  => $supplier->id,
            'warehouse_id' => $wh->id,
            'items' => [['inventory_item_id' => $item->id, 'quantity' => 5, 'unit_cost' => 20]],
        ])->json('data');

        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/submit")->assertStatus(200);
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/cancel", ['reason' => 'cancelled by supplier'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        // Cannot submit/receive a cancelled PO.
        $this->postJson("/api/inventory/operations/purchase-orders/{$po['id']}/submit")->assertStatus(422);
    }

    public function test_granular_permission_gates_transfer_actions(): void
    {
        $staff = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $staff->assignRole('staff');
        Sanctum::actingAs($staff);

        // staff has view inventory but not transfer actions.
        $this->postJson('/api/inventory/operations/transfers', [
            'source_warehouse_id'      => 1,
            'destination_warehouse_id' => 2,
            'items' => [['inventory_item_id' => 1, 'quantity' => 1]],
        ])->assertStatus(403);
    }
}

