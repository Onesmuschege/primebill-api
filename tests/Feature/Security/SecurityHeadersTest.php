<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class SecurityHeadersTest extends TestCase
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
    public function authenticated_responses_include_security_headers()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-XSS-Protection', '1; mode=block')
            ->assertHeader('Referrer-Policy')
            ->assertHeader('Permissions-Policy');
    }

    /** @test */
    public function security_headers_include_content_security_policy()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertHeader('Content-Security-Policy');
    }

    /** @test */
    public function security_headers_include_strict_transport_security()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertHeader('Strict-Transport-Security');
    }
}
