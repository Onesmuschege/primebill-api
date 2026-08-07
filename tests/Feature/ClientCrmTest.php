<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\ClientTag;
use App\Models\ClientCustomField;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ClientCrmTest extends TestCase
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

        // Grant necessary permissions for CRM features
        $this->user->givePermissionTo([
            'view clients',
            'edit clients',
            'view notes',
            'create notes',
            'edit notes',
            'delete notes',
            'view tags',
            'create tags',
            'edit tags',
            'delete tags',
            'view custom-fields',
            'create custom-fields',
            'edit custom-fields',
            'delete custom-fields',
        ]);
    }

    public function test_user_can_create_client_note(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson("/api/clients/{$this->client->id}/notes", [
            'note' => 'This is a test note',
            'type' => 'general',
            'priority' => 'normal',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Note created successfully',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'note',
                    'type',
                    'priority',
                    'creator' => [
                        'id',
                        'name',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('client_notes', [
            'client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'note' => 'This is a test note',
            'type' => 'general',
        ]);
    }

    public function test_user_can_toggle_pin_on_note(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $note = ClientNote::factory()->create([
            'client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'is_pinned' => false,
        ]);

        $response = $this->postJson("/api/clients/{$this->client->id}/notes/{$note->id}/pin");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Note pinned',
            ]);

        $this->assertDatabaseHas('client_notes', [
            'id' => $note->id,
            'is_pinned' => true,
        ]);
    }

    public function test_user_can_create_tag(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/tags', [
            'name' => 'VIP',
            'color' => '#FF5733',
            'description' => 'Very Important Client',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Tag created successfully',
            ]);

        $this->assertDatabaseHas('client_tags', [
            'tenant_id' => $this->tenant->id,
            'name' => 'VIP',
            'color' => '#FF5733',
        ]);
    }

    public function test_user_can_assign_tag_to_client(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $tag = ClientTag::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->postJson("/api/clients/{$this->client->id}/tags/assign", [
            'client_tag_id' => $tag->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Tag assigned to client',
            ]);

        $this->assertDatabaseHas('client_tag_assignments', [
            'client_id' => $this->client->id,
            'client_tag_id' => $tag->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_user_can_create_custom_field(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/custom-fields', [
            'name' => 'occupation',
            'label' => 'Occupation',
            'type' => 'text',
            'is_required' => false,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Custom field created successfully',
            ]);

        $this->assertDatabaseHas('client_custom_fields', [
            'tenant_id' => $this->tenant->id,
            'name' => 'occupation',
            'label' => 'Occupation',
            'type' => 'text',
        ]);
    }

    public function test_user_can_update_client_custom_field_values(): void
    {
        $this->actingAs($this->user, 'sanctum');

        $field = ClientCustomField::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->putJson("/api/clients/{$this->client->id}/custom-fields", [
            'values' => [
                $field->name => 'Engineer',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Custom field values updated successfully',
            ]);

        $this->assertDatabaseHas('client_custom_field_values', [
            'client_id' => $this->client->id,
            'client_custom_field_id' => $field->id,
            'value' => 'Engineer',
        ]);
    }

    public function test_user_can_view_client_notes(): void
    {
        $this->actingAs($this->user, 'sanctum');

        ClientNote::factory()->create([
            'client_id' => $this->client->id,
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/clients/{$this->client->id}/notes");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonCount(1, 'data');
    }
}
