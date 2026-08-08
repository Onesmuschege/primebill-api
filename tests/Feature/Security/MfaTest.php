<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class MfaTest extends TestCase
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

    /** @test */
    public function authenticated_user_can_generate_mfa_secret()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/mfa/generate');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'secret',
                'qr_code_url',
            ]);
    }

    /** @test */
    public function authenticated_user_can_enable_mfa_with_valid_code()
    {
        Sanctum::actingAs($this->user);

        // Generate secret first
        $secretResponse = $this->postJson('/api/mfa/generate');
        $secret = $secretResponse->json('secret');

        // Enable MFA (use first 6 chars of secret as code for testing)
        $response = $this->postJson('/api/mfa/enable', [
            'code' => substr($secret, 0, 6),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'MFA enabled successfully',
            ])
            ->assertJsonStructure([
                'message',
                'backup_codes',
            ]);

        $this->user->refresh();
        $this->assertTrue($this->user->mfa_enabled);
    }

    /** @test */
    public function authenticated_user_can_disable_mfa_with_password()
    {
        $this->user->update([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('test_secret'),
            'mfa_backup_codes' => encrypt(json_encode(['CODE12345'])),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/mfa/disable', [
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'MFA disabled successfully',
            ]);

        $this->user->refresh();
        $this->assertFalse($this->user->mfa_enabled);
    }

    /** @test */
    public function user_cannot_disable_mfa_with_wrong_password()
    {
        $this->user->update([
            'mfa_enabled' => true,
            'mfa_secret' => encrypt('test_secret'),
            'mfa_backup_codes' => encrypt(json_encode(['CODE12345'])),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/mfa/disable', [
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Invalid password',
            ]);

        $this->user->refresh();
        $this->assertTrue($this->user->mfa_enabled);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_mfa_endpoints()
    {
        $response = $this->postJson('/api/mfa/generate');

        $response->assertStatus(401);
    }
}
