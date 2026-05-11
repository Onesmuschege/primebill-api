<?php

use App\Models\User;
use App\Models\Client;
use App\Models\Plan;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->assignRole('super_admin');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

test('can list all clients', function () {
    Client::factory(5)->create();

    $response = $this->getJson('/api/clients', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['*' => ['id', 'first_name', 'email', 'status']],
            'meta' => ['total', 'per_page'],
        ]);
});

test('can create a client with valid data', function () {
    $data = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '0791234567',
        'id_number' => '12345678',
        'address' => '123 Main St',
        'city' => 'Nairobi',
        'account_type' => 'residential',
        'plan_id' => Plan::factory()->create()->id,
    ];

    $response = $this->postJson('/api/clients', $data, [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.email', 'john@example.com');

    $this->assertDatabaseHas('clients', ['email' => 'john@example.com']);
});

test('cannot create client with invalid email', function () {
    $data = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'invalid-email',
        'phone' => '0791234567',
        'id_number' => '12345678',
        'address' => '123 Main St',
        'city' => 'Nairobi',
        'account_type' => 'residential',
        'plan_id' => Plan::factory()->create()->id,
    ];

    $response = $this->postJson('/api/clients', $data, [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('can update a client', function () {
    $client = Client::factory()->create();

    $response = $this->putJson("/api/clients/{$client->id}", [
        'first_name' => 'Jane',
        'phone' => '0795555555',
    ], [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertStatus(200);
    $this->assertEquals('Jane', $client->fresh()->first_name);
});

test('can suspend a client', function () {
    $client = Client::factory()->create(['status' => 'active']);

    $response = $this->postJson("/api/clients/{$client->id}/suspend", [], [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertStatus(200);
    $this->assertEquals('suspended', $client->fresh()->status);
});
