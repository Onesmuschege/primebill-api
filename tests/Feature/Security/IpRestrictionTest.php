<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class IpRestrictionTest extends TestCase
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
    public function user_without_restrictions_is_not_blocked()
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/auth/me')
            ->assertStatus(200);
    }

    #[Test]
    public function user_with_matching_ip_is_allowed()
    {
        $this->user->update([
            'allowed_ips' => ['127.0.0.1'],
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/auth/me')
            ->assertStatus(200);
    }

    #[Test]
    public function user_with_non_matching_ip_is_denied()
    {
        $this->user->update([
            'allowed_ips' => ['192.168.1.10'],
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/auth/me')
            ->assertStatus(403)
            ->assertJson([
                'message' => 'Access denied from this IP address.',
            ]);
    }

    #[Test]
    public function user_with_cidr_range_is_allowed()
    {
        $this->user->update([
            'allowed_ips' => ['127.0.0.0/8'],
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/auth/me')
            ->assertStatus(200);
    }

    #[Test]
    public function user_with_cidr_range_that_does_not_match_is_denied()
    {
        $this->user->update([
            'allowed_ips' => ['10.0.0.0/8'],
        ]);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/auth/me')
            ->assertStatus(403);
    }
}

