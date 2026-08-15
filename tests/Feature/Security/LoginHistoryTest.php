<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\LoginHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);
    }

    #[Test]
    public function login_records_login_history()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('login_history', [
            'user_id' => $this->user->id,
            'email' => 'test@example.com',
            'success' => true,
        ]);
    }

    #[Test]
    public function failed_login_records_failed_attempt()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(401);

        $this->assertDatabaseHas('login_history', [
            'email' => 'test@example.com',
            'success' => false,
            'failure_reason' => 'Invalid credentials',
        ]);
    }

    #[Test]
    public function authenticated_user_can_view_their_login_history()
    {
        Sanctum::actingAs($this->user);

        LoginHistory::create([
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Agent',
            'device' => 'Test Device',
            'success' => true,
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->getJson('/api/login-history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'ip_address',
                    'user_agent',
                    'device',
                    'success',
                    'logged_in_at',
                    'created_at',
                ],
            ]);
    }

    #[Test]
    public function logout_records_logout_time()
    {
        Sanctum::actingAs($this->user);

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $response->assertStatus(200);

        $this->assertDatabaseHas('login_history', [
            'user_id' => $this->user->id,
            'success' => true,
        ]);
    }
}
