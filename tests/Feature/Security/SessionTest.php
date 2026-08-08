<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class SessionTest extends TestCase
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
    public function authenticated_user_can_list_their_sessions()
    {
        Sanctum::actingAs($this->user);

        // Sanctum::actingAs() uses a non-persisted TransientToken, so it
        // does NOT create a row in personal_access_tokens. Create three
        // real persisted tokens so the list endpoint returns exactly 3.
        $this->user->createToken('test-session-1');
        $this->user->createToken('test-session-2');
        $this->user->createToken('test-session-3');

        $response = $this->getJson('/api/sessions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);

        // The three persisted tokens created above
        $this->assertCount(3, $response->json('data.data'));
    }

    /** @test */
    public function user_can_revoke_a_session()
    {
        Sanctum::actingAs($this->user);

        $token = $this->user->createToken('revoke-me');
        $tokenId = $token->accessToken->id;

        $response = $this->deleteJson("/api/sessions/{$tokenId}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ]);
    }

    /** @test */
    public function user_cannot_revoke_someone_elses_session()
    {
        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $otherToken = $otherUser->createToken('other-session');
        $otherTokenId = $otherToken->accessToken->id;

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/sessions/{$otherTokenId}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherTokenId,
        ]);
    }

    /** @test */
    public function user_can_revoke_all_other_sessions()
    {
        $this->user->createToken('stale-session-1');
        $this->user->createToken('stale-session-2');

        // Authenticate with a specific token so we know which one is current
        $token = $this->user->createToken('current-session');
        $currentTokenId = $token->accessToken->id;

        $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
            ->deleteJson('/api/sessions/revoke-all')
            ->assertStatus(200);

        // Current token should survive
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $currentTokenId,
        ]);

        // The 2 stale sessions should be gone; only the current one remains
        $this->assertSame(1, \Laravel\Sanctum\PersonalAccessToken::where('tokenable_id', $this->user->id)->count());
    }
}

