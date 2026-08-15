<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MfaChallengeTest extends TestCase
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
    public function login_without_mfa_returns_token()
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'email', 'roles', 'permissions'],
                ],
            ]);
    }

    #[Test]
    public function login_with_mfa_returns_challenge_token()
    {
        $this->user->update([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('test_secret_key_12345'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => ['mfa_required' => true],
            ])
            ->assertJsonStructure([
                'data' => [
                    'mfa_required',
                    'mfa_token',
                    'user' => ['id', 'email', 'name'],
                ],
            ]);
    }

    #[Test]
    public function challenge_with_valid_code_returns_session_token()
    {
        $this->user->update([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('test_secret_key_12345'),
        ]);

        // Login to get the mfa-pending token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $mfaToken = $loginResponse->json('data.mfa_token');

        // Use the mfa-pending token to challenge with a 6-digit code
        $response = $this->withHeader('Authorization', 'Bearer ' . $mfaToken)
            ->postJson('/api/mfa/challenge', [
                'code' => '123456',
            ]);

$response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'roles', 'permissions'],
            ]);
    }

    #[Test]
    public function challenge_with_invalid_code_returns_error()
    {
        $this->user->update([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('test_secret_key_12345'),
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $mfaToken = $loginResponse->json('data.mfa_token');

        // Use a non-6-digit code which should fail
        $response = $this->withHeader('Authorization', 'Bearer ' . $mfaToken)
            ->postJson('/api/mfa/challenge', [
                'code' => 'short',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Invalid verification code',
            ]);
    }

    #[Test]
    public function challenge_without_mfa_pending_token_is_rejected()
    {
        // Regular auth token (not mfa-pending)
        $token = $this->user->createToken('test-token', ['admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/mfa/challenge', [
                'code' => '123456',
            ]);

        $response->assertStatus(403);
    }
}
