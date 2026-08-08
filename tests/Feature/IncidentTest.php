<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Client;
use App\Models\Router;
use App\Models\NetworkIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class IncidentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private User $admin;
    private Router $router;

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
        $permissions = [
            'view incidents',
            'create incidents',
            'edit incidents',
            'delete incidents',
        ];
        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        $adminRole->givePermissionTo($permissions);
        $this->admin->assignRole($adminRole);

        $this->router = Router::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Router',
            'ip_address' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret',
        ]);
    }

    /** @test */
    public function admin_can_create_incident()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/incidents', [
            'title' => 'Network Outage',
            'description' => 'Major outage in sector 5',
            'severity' => 'critical',
            'affected_device_id' => $this->router->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'id',
                'title',
                'severity',
                'status',
                'affected_device_id',
                'creator',
            ],
        ]);

        $this->assertDatabaseHas('network_incidents', [
            'title' => 'Network Outage',
            'severity' => 'critical',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /** @test */
    public function user_without_permission_cannot_create_incident()
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/incidents', [
            'title' => 'Network Outage',
            'severity' => 'critical',
        ])->assertStatus(403);
    }

    /** @test */
    public function admin_can_list_incidents()
    {
        $incident = NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Incident',
            'severity' => 'high',
            'status' => 'detected',
            'detected_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/incidents');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'data',
                'current_page',
                'total',
            ],
        ]);
    }

    /** @test */
    public function admin_can_acknowledge_incident()
    {
        $incident = NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Incident',
            'severity' => 'high',
            'status' => 'detected',
            'detected_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/incidents/{$incident->id}/acknowledge");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Incident acknowledged',
        ]);

        $this->assertDatabaseHas('network_incidents', [
            'id' => $incident->id,
            'status' => 'acknowledged',
        ]);
    }

    /** @test */
    public function admin_can_resolve_incident()
    {
        $incident = NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Incident',
            'severity' => 'high',
            'status' => 'investigating',
            'detected_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/incidents/{$incident->id}/resolve", [
            'resolution' => 'Replaced faulty switch',
            'root_cause' => 'Hardware failure',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Incident resolved',
        ]);

        $this->assertDatabaseHas('network_incidents', [
            'id' => $incident->id,
            'status' => 'resolved',
            'resolution' => 'Replaced faulty switch',
        ]);
    }

    /** @test */
    public function admin_can_close_incident()
    {
        $incident = NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Incident',
            'severity' => 'high',
            'status' => 'resolved',
            'detected_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/incidents/{$incident->id}/close");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Incident closed',
        ]);

        $this->assertDatabaseHas('network_incidents', [
            'id' => $incident->id,
            'status' => 'closed',
        ]);
    }

    /** @test */
    public function incident_rejects_invalid_transitions()
    {
        $incident = NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Incident',
            'severity' => 'high',
            'status' => 'closed',
            'detected_at' => now(),
            'created_by' => $this->admin->id,
            'closed_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);

        // Try to acknowledge a closed incident - should fail
        $response = $this->postJson("/api/incidents/{$incident->id}/acknowledge");
        $response->assertStatus(422);
    }

    /** @test */
    public function incident_stats_returns_correct_data()
    {
        NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Open Incident',
            'severity' => 'critical',
            'status' => 'detected',
            'detected_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Resolved Incident',
            'severity' => 'high',
            'status' => 'resolved',
            'detected_at' => now(),
            'resolved_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/incidents/stats');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        $stats = $response->json('data');
        $this->assertEquals(1, $stats['open_incidents']);
        $this->assertEquals(1, $stats['critical_incidents']);
    }

    /** @test */
    public function tenant_isolation_works_for_incidents()
    {
        $tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b']);
        $userB = User::create([
            'name' => 'User B',
            'email' => 'userb@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $tenantB->id,
        ]);

        $incidentB = NetworkIncident::create([
            'tenant_id' => $tenantB->id,
            'title' => 'Tenant B Incident',
            'severity' => 'high',
            'status' => 'detected',
            'detected_at' => now(),
            'created_by' => $userB->id,
        ]);

        $incidentA = NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Tenant A Incident',
            'severity' => 'high',
            'status' => 'detected',
            'detected_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/incidents');
        $response->assertStatus(200);

        $incidents = $response->json('data.data');
        $titles = array_column($incidents, 'title');

        $this->assertContains('Tenant A Incident', $titles);
        $this->assertNotContains('Tenant B Incident', $titles);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_incidents()
    {
        $this->getJson('/api/incidents')->assertStatus(401);
    }
}
