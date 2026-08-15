<?php

namespace Tests\Feature\ServiceDesk;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\Client;
use App\Models\KnowledgeBaseArticle;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class TicketRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $agent;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'ServiceDesk Tenant',
            'slug' => 'servicedesk-tenant',
        ]);

        $this->agent = User::create([
            'name' => 'Support Agent',
            'email' => 'agent@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $role = Role::findOrCreate('support');
        foreach (['view tickets', 'create tickets', 'edit tickets'] as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        $role->givePermissionTo(['view tickets', 'create tickets', 'edit tickets']);
        $this->agent->assignRole($role);

        $this->client = Client::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'email' => 'bob@example.com',
            'phone' => '+254700000002',
        ]);
    }

    private function ticket(): Ticket
    {
        return Ticket::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'subject' => 'No internet after installation',
            'description' => 'Customer reports connectivity down',
            'priority' => 'high',
            'status' => 'open',
        ]);
    }

    private function workOrder(): WorkOrder
    {
        return WorkOrder::create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $this->client->id,
            'work_order_number' => 'WO-TKT-' . uniqid(),
            'type' => 'repair',
            'status' => 'in_progress',
            'priority' => 'high',
            'description' => 'Fix drop connection',
            'created_by' => $this->agent->id,
        ]);
    }

    private function article(): KnowledgeBaseArticle
    {
        return KnowledgeBaseArticle::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'ONT not syncing — power cycle the ONU',
            'slug' => 'ont-not-syncing',
            'content' => 'Steps to recover an unsynced ONU.',
            'is_published' => true,
        ]);
    }

    #[Test]
    public function ticket_can_be_linked_to_a_work_order()
    {
        Sanctum::actingAs($this->agent);

        $ticket = $this->ticket();
        $workOrder = $this->workOrder();

        $response = $this->postJson("/api/tickets/{$ticket->id}/work-order", [
            'work_order_id' => $workOrder->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Work order linked to ticket']);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'work_order_id' => $workOrder->id,
        ]);

        $this->assertEquals($workOrder->id, $response->json('data.work_order.id'));
    }

    #[Test]
    public function ticket_show_embeds_the_work_order_relationship()
    {
        Sanctum::actingAs($this->agent);

        $ticket = $this->ticket();
        $workOrder = $this->workOrder();
        $ticket->update(['work_order_id' => $workOrder->id]);

        $response = $this->getJson("/api/tickets/{$ticket->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertEquals($workOrder->id, $response->json('data.work_order.id'));
        $this->assertEquals($workOrder->work_order_number, $response->json('data.work_order.work_order_number'));
    }

    #[Test]
    public function ticket_can_be_unlinked_from_a_work_order()
    {
        Sanctum::actingAs($this->agent);

        $ticket = $this->ticket();
        $workOrder = $this->workOrder();
        $ticket->update(['work_order_id' => $workOrder->id]);

        $this->postJson("/api/tickets/{$ticket->id}/unlink-work-order")
            ->assertStatus(200)
            ->assertJson(['message' => 'Work order unlinked from ticket']);

        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'work_order_id' => null,
        ]);
    }

    #[Test]
    public function knowledge_reference_can_be_attached_and_listed()
    {
        Sanctum::actingAs($this->agent);

        $ticket = $this->ticket();
        $article = $this->article();

        $attach = $this->postJson("/api/tickets/{$ticket->id}/knowledge", [
            'knowledge_base_article_id' => $article->id,
            'note' => 'Used this to recover the ONU.',
        ]);

        $attach->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Knowledge reference attached']);

        $list = $this->getJson("/api/tickets/{$ticket->id}/knowledge");
        $list->assertStatus(200);

        $refs = $list->json('data');
        $this->assertCount(1, $refs);
        $this->assertEquals($article->id, $refs[0]['article']['id']);
        $this->assertEquals('Used this to recover the ONU.', $refs[0]['note']);
    }

    #[Test]
    public function duplicate_knowledge_reference_is_not_duplicated()
    {
        Sanctum::actingAs($this->agent);

        $ticket = $this->ticket();
        $article = $this->article();

        $this->postJson("/api/tickets/{$ticket->id}/knowledge", [
            'knowledge_base_article_id' => $article->id,
        ])->assertStatus(201);

        $this->postJson("/api/tickets/{$ticket->id}/knowledge", [
            'knowledge_base_article_id' => $article->id,
        ])->assertStatus(201);

        $this->assertDatabaseCount('ticket_knowledge_refs', 1);
    }

    #[Test]
    public function knowledge_reference_can_be_removed()
    {
        Sanctum::actingAs($this->agent);

        $ticket = $this->ticket();
        $article = $this->article();

        $this->postJson("/api/tickets/{$ticket->id}/knowledge", [
            'knowledge_base_article_id' => $article->id,
        ])->assertStatus(201);

        $refId = \App\Models\TicketKnowledgeRef::first()->id;

        $this->deleteJson("/api/tickets/{$ticket->id}/knowledge/{$refId}")
            ->assertStatus(200)
            ->assertJson(['message' => 'Knowledge reference removed']);

        $this->assertDatabaseCount('ticket_knowledge_refs', 0);
    }

    #[Test]
    public function referencing_unknown_article_is_rejected()
    {
        Sanctum::actingAs($this->agent);

        $ticket = $this->ticket();

        $this->postJson("/api/tickets/{$ticket->id}/knowledge", [
            'knowledge_base_article_id' => 999999,
        ])->assertStatus(422);
    }
}
