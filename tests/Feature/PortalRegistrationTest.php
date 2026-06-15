<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_registration_creates_client_and_account(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        $response = $this->postJson('/api/portal/register', [
            'first_name'            => 'Jane',
            'last_name'             => 'Doe',
            'email'                 => 'jane@example.com',
            'phone'                 => '254712345678',
            'plan_id'               => $plan->id,
            'username'              => 'janedoe',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.username', 'janedoe');

        $this->assertDatabaseHas('clients', ['email' => 'jane@example.com']);
        $this->assertDatabaseHas('client_accounts', ['username' => 'janedoe']);
    }

    public function test_portal_registration_rejects_duplicate_email(): void
    {
        $plan = Plan::factory()->create(['is_active' => true]);

        $payload = [
            'first_name'            => 'Jane',
            'last_name'             => 'Doe',
            'email'                 => 'dup@example.com',
            'phone'                 => '254712345679',
            'plan_id'               => $plan->id,
            'username'              => 'userone',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->postJson('/api/portal/register', $payload)->assertStatus(201);

        $payload['username'] = 'usertwo';
        $payload['phone']    = '254712345680';

        $this->postJson('/api/portal/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
