<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_creation_dispatches_provisioning(): void
    {
        Log::fake();

        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $token = $user->createToken('test')->plainTextToken;

        $client = Client::factory()->create();
        $plan   = Plan::factory()->create();

        $response = $this->postJson("/api/clients/{$client->id}/accounts", [
            'plan_id'  => $plan->id,
            'username' => 'testuser01',
            'password' => 'secret123',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(201);

        Log::assertLogged(function ($log) {
            return str_contains($log->message, 'MockRouterAdapter:createUser');
        });
    }

    public function test_client_suspend_dispatches_network_suspend(): void
    {
        Log::fake();

        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $token = $user->createToken('test')->plainTextToken;

        $client = Client::factory()->create(['status' => 'active']);
        $plan   = Plan::factory()->create();

        $this->postJson("/api/clients/{$client->id}/accounts", [
            'plan_id'  => $plan->id,
            'username' => 'suspenduser',
            'password' => 'secret123',
        ], ['Authorization' => "Bearer {$token}"]);

        Log::fake();

        $response = $this->postJson("/api/clients/{$client->id}/suspend", [], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);

        Log::assertLogged(function ($log) {
            return str_contains($log->message, 'MockRouterAdapter:suspendUser');
        });
    }
}
