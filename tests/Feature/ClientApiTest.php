<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user  = User::factory()->create();
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    public function test_can_list_all_clients(): void
    {
        Client::factory()->count(3)->create();

        $response = $this->getJson('/api/clients', [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_can_create_a_client_with_valid_data(): void
    {
        $plan = Plan::factory()->create();

        $data = [
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'email'        => 'john@example.com',
            'phone'        => '254712345678',
            'id_number'    => '12345678',
            'address'      => '123 Main Street Nairobi',
            'city'         => 'Nairobi',
            'account_type' => 'residential',
            'plan_id'      => $plan->id,
        ];

        $response = $this->postJson('/api/clients', $data, [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'john@example.com');

        $this->assertDatabaseHas('clients', ['email' => 'john@example.com']);
    }

    public function test_can_suspend_a_client(): void
    {
        $client = Client::factory()->create(['status' => 'active']);

        $response = $this->postJson("/api/clients/{$client->id}/suspend", [], [
            'Authorization' => "Bearer {$this->token}",
        ]);

        $response->assertStatus(200);
        $this->assertEquals('suspended', $client->fresh()->status);
    }
}
