<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::create([
            'name' => 'Basic User',
            'email' => 'user@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $adminRole = Role::findOrCreate('admin');
        $this->admin->assignRole($adminRole);
    }

    /** @test */
    public function admin_can_access_clients_index()
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/clients')
            ->assertStatus(200);
    }

    /** @test */
    public function user_without_view_clients_permission_is_denied()
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/clients')
            ->assertStatus(403);
    }

    /** @test */
    public function permission_denied_is_audited_in_system_log()
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/clients')
            ->assertStatus(403);

        $this->assertDatabaseHas('system_logs', [
            'user_id' => $this->user->id,
            'action' => 'security.permission_denied',
        ]);
    }

    /** @test */
    public function user_with_view_clients_permission_can_access()
    {
        $role = Role::findOrCreate('viewer');
        $role->givePermissionTo('view clients');
        $this->user->assignRole($role);

        Sanctum::actingAs($this->user);

        $this->getJson('/api/clients')
            ->assertStatus(200);
    }

    /** @test */
    public function user_without_view_inventory_permission_cannot_access_inventory()
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/inventory')
            ->assertStatus(403);
    }

    /** @test */
    public function admin_can_access_inventory()
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/inventory')
            ->assertStatus(200);
    }
}

