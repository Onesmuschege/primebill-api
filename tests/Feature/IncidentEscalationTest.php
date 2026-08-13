<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\NetworkIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class IncidentEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Escalation Tenant',
            'slug' => 'escalation-tenant',
        ]);

        $this->admin = User::create([
            'name' => 'NOC Lead',
            'email' => 'noc-lead@example.com',
            'password' => bcrypt('password123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $role = Role::findOrCreate('noc');
        $permissions = ['view incidents', 'create incidents', 'edit incidents'];
        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        $role->givePermissionTo($permissions);
        $this->admin->assignRole($role);
    }

    private function incident(string $status = 'detected'): NetworkIncident
    {
        return NetworkIncident::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Fiber cut near zone 4',
            'severity' => 'medium',
            'status' => $status,
            'detected_at' => now(),
            'created_by' => $this->admin->id,
        ]);
    }

    /** @test */
    public function open_incident_can_be_escalated()
    {
        Sanctum::actingAs($this->admin);

        $incident = $this->incident();

        $response = $this->postJson("/api/incidents/{$incident->id}/escalate", [
            'escalation_reason' => 'Customers beyond 500 affected, requires senior NOC',
            'severity' => 'high',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Incident escalated']);

        $this->assertDatabaseHas('network_incidents', [
            'id' => $incident->id,
            'escalation_level' => 1,
            'escalated_by' => $this->admin->id,
            'severity' => 'high',
        ]);

        $this->assertNotNull($response->json('data.escalated_at'));
        $this->assertEquals('Customers beyond 500 affected, requires senior NOC', $response->json('data.escalation_reason'));
    }

    /** @test */
    public function escalation_requires_a_reason()
    {
        Sanctum::actingAs($this->admin);

        $incident = $this->incident();

        $this->postJson("/api/incidents/{$incident->id}/escalate", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('escalation_reason');

        $this->assertDatabaseHas('network_incidents', [
            'id' => $incident->id,
            'escalation_level' => 0,
        ]);
    }

    /** @test */
    public function escalation_raises_level_up_to_the_cap()
    {
        Sanctum::actingAs($this->admin);

        $incident = $this->incident();
        // Pre-escalate twice so we are already at the cap.
        $incident->escalate($this->admin->id, 'first');
        $incident->escalate($this->admin->id, 'second');

        $this->postJson("/api/incidents/{$incident->id}/escalate", [
            'escalation_reason' => 'third bump',
        ])->assertStatus(200);

        $this->assertDatabaseHas('network_incidents', [
            'id' => $incident->id,
            'escalation_level' => 3,
        ]);
    }

    /** @test */
    public function resolved_and_closed_incidents_cannot_be_escalated()
    {
        Sanctum::actingAs($this->admin);

        $closed = $this->incident('closed');
        $this->postJson("/api/incidents/{$closed->id}/escalate", [
            'escalation_reason' => 'too late',
        ])->assertStatus(422);

        $resolved = $this->incident('resolved');
        $this->postJson("/api/incidents/{$resolved->id}/escalate", [
            'escalation_reason' => 'too late',
        ])->assertStatus(422);
    }

    /** @test */
    public function escalation_does_not_break_the_incident_lifecycle()
    {
        Sanctum::actingAs($this->admin);

        $incident = $this->incident();
        $this->postJson("/api/incidents/{$incident->id}/escalate", [
            'escalation_reason' => 'bump',
        ])->assertStatus(200);

        // The lifecycle still proceeds normally after an escalation.
        $this->postJson("/api/incidents/{$incident->id}/acknowledge")->assertStatus(200);
        $this->postJson("/api/incidents/{$incident->id}/resolve", [
            'resolution' => 'Spliced the fiber and restored service',
        ])->assertStatus(200);
        $this->postJson("/api/incidents/{$incident->id}/close")->assertStatus(200);

        $this->assertDatabaseHas('network_incidents', [
            'id' => $incident->id,
            'status' => 'closed',
            'escalation_level' => 1,
        ]);
    }
}
