<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\Client;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a']);
        $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);

        $this->userA = User::create([
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenantA->id,
        ]);

        $this->userA->givePermissionTo([
            'view clients',
            'create clients',
            'view routers',
        ]);
    }

    #[Test]
    public function client_from_other_tenant_is_not_visible()
    {
        Client::create([
            'tenant_id' => $this->tenantB->id,
            'first_name' => 'TenantB',
            'last_name' => 'Client',
            'email' => 'clientb@example.com',
            'phone' => '0712000000',
            'status' => 'active',
        ]);

        Client::create([
            'tenant_id' => $this->tenantA->id,
            'first_name' => 'TenantA',
            'last_name' => 'Client',
            'email' => 'clienta@example.com',
            'phone' => '0712000001',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/clients');
        $response->assertStatus(200);

        $clients = $response->json('data.data');
        $emails = array_column($clients, 'email');

        $this->assertContains('clienta@example.com', $emails);
        $this->assertNotContains('clientb@example.com', $emails);
    }

    #[Test]
    public function router_from_other_tenant_is_not_visible()
    {
        Router::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Router',
            'ip_address' => '192.168.100.1',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/routers');
        $response->assertStatus(200);

        $routers = $response->json('data');
        $names = array_column($routers, 'name');

        $this->assertNotContains('Tenant B Router', $names);
    }

    #[Test]
    public function tenant_b_data_is_not_accessible_by_id()
    {
        $clientB = Client::create([
            'tenant_id' => $this->tenantB->id,
            'first_name' => 'TenantB',
            'last_name' => 'Client',
            'email' => 'clientb2@example.com',
            'phone' => '0712000002',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->userA);

        $resp = $this->getJson("/api/clients/{$clientB->id}");
        fwrite(STDERR, "\nHTTP_STATUS: {$resp->status()}\n");
        fwrite(STDERR, "CURRENT_TENANT bound in request: " . (app()->bound('currentTenant') ? app('currentTenant')->id : 'NULL') . "\n");

        $resp->assertStatus(404);
    }

    #[Test]
    public function auto_injects_tenant_id_on_create()
    {
        $this->userA->givePermissionTo('create clients');

        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/clients', [
            'first_name' => 'New',
            'last_name' => 'Client',
            'email' => 'newclient@example.com',
            'phone' => '0712000003',
            'status' => 'active',
        ]);

        $response->assertStatus(201);

        $clientId = $response->json('data.id');
        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'tenant_id' => $this->tenantA->id,
        ]);
    }

    #[Test]
    public function admin_users_from_other_tenant_are_not_visible()
    {
        // Another admin belonging to tenant B must never appear in tenant A's
        // admin-users list. User has no BelongsToTenant global scope (login needs
        // to look up by email), so the controller must scope explicitly — this
        // test guards against regressions in that manual scoping.
        User::create([
            'name' => 'Admin B',
            'email' => 'adminb@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenantB->id,
        ]);

        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/admin/users');
        $response->assertStatus(200);

        $users = $response->json('data');
        $emails = array_column($users, 'email');

        $this->assertNotContains('adminb@example.com', $emails);
    }

    #[Test]
    public function created_admin_user_is_assigned_to_current_tenant()
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'email' => 'newstaff@example.com',
            'password' => 'password123',
            'role' => 'staff',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'id' => $response->json('data.id'),
            'tenant_id' => $this->tenantA->id,
        ]);
    }
}
