<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ApiKeyTest extends TestCase
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
    public function authenticated_user_can_create_api_key()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/api-keys', [
            'name' => 'Test API Key',
            'scopes' => ['read', 'write'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'name',
                'key_id',
                'key_secret',
                'scopes',
                'created_at',
            ]);

        $this->assertDatabaseHas('api_keys', [
            'user_id' => $this->user->id,
            'name' => 'Test API Key',
            'revoked' => false,
        ]);
    }

    /** @test */
    public function authenticated_user_can_list_their_api_keys()
    {
        Sanctum::actingAs($this->user);

        ApiKey::create([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Key',
            'key_id' => 'test_key_id',
            'key_hash' => bcrypt('secret'),
            'key_secret' => encrypt('secret'),
        ]);

        $response = $this->getJson('/api/api-keys');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'key_id',
                    'key_secret',
                    'last_used_at',
                    'expires_at',
                    'revoked',
                ],
            ]);
    }

    /** @test */
    public function authenticated_user_can_revoke_their_api_key()
    {
        Sanctum::actingAs($this->user);

        $apiKey = ApiKey::create([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Key',
            'key_id' => 'test_key_id',
            'key_hash' => bcrypt('secret'),
            'key_secret' => encrypt('secret'),
        ]);

        $response = $this->deleteJson("/api/api-keys/{$apiKey->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'API key revoked successfully',
            ]);

        $this->assertDatabaseHas('api_keys', [
            'id' => $apiKey->id,
            'revoked' => true,
        ]);
    }

    /** @test */
    public function user_cannot_revoke_someone_elses_api_key()
    {
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $otherKey = ApiKey::create([
            'user_id' => $otherUser->id,
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Key',
            'key_id' => 'other_key_id',
            'key_hash' => bcrypt('secret'),
            'key_secret' => encrypt('secret'),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/api-keys/{$otherKey->id}");

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'API key not found',
            ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_api_keys()
    {
        $response = $this->getJson('/api/api-keys');

        $response->assertStatus(401);
    }
}
