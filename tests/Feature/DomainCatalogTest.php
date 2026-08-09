<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DomainCatalogTest
 *
 * Covers the reconciliation catalog domains wired in Phase D: validates the
 * full Model → Controller → Route → Permission → Tenant-isolation chain for a
 * representative resource in every domain group.
 */
class DomainCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected User $staff;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');

        $this->staff = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        Sanctum::actingAs($this->user);
    }

    public static function crudProvider(): array
    {
        return [
            'service-catalog/service-templates' => [['service-catalog', 'service-templates', ['name' => 'Fiber', 'service_type' => 'fiber']]],
            'equipment/customer-equipment' => [['equipment', 'customer-equipment', ['client_id' => 'CLIENT', 'type' => 'router']]],
            'router-config/router-templates' => [['router-config', 'router-templates', ['name' => 'Core Template']]],
            'radius-advanced/radius-profiles' => [['radius-advanced', 'radius-profiles', ['name' => 'Default Profile', 'code' => 'DEFAULTPROF']]],
            'inventory-ext/warehouses' => [['inventory-ext', 'warehouses', ['name' => 'Main Warehouse']]],
            'inventory-ext/suppliers' => [['inventory-ext', 'suppliers', ['name' => 'Acme Supply']]],
            'support-catalog/departments' => [['support-catalog', 'departments', ['name' => 'NOC']]],
            'support-catalog/sla-policies' => [['support-catalog', 'sla-policies', ['name' => 'VIP SLA']]],
            'communications/communication-templates' => [['communications', 'communication-templates', ['name' => 'Welcome Email', 'type' => 'email', 'category' => 'marketing', 'content' => 'Hi there']]],
            'communications/campaigns' => [['communications', 'campaigns', ['name' => 'Summer Promo', 'type' => 'email', 'category' => 'marketing', 'content' => 'Promo', 'status' => 'draft']]],
            'customer-experience/customer-interactions' => [['customer-experience', 'customer-interactions', ['client_id' => 'CLIENT', 'type' => 'call']]],
            'security-admin/security-events' => [['security-admin', 'security-events', ['event' => 'test.event']]],
            'field-ops/work-order-templates' => [['field-ops', 'work-order-templates', ['name' => 'Standard Install', 'type' => 'installation']]],
            'reporting/saved-reports' => [['reporting', 'saved-reports', ['name' => 'AR Aging', 'type' => 'financial']]],
            'reporting/dashboards' => [['reporting', 'dashboards', ['name' => 'Operations', 'type' => 'team']]],
        ];
    }

    private function resolvePayload(array $payload): array
    {
        foreach ($payload as $key => $val) {
            if ($val === 'CLIENT') {
                $payload[$key] = $this->client->id;
            }
        }

        return $payload;
    }

    private function updatePayload(array $payload): array
    {
        foreach (['name', 'title', 'type'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $payload[$field].'-UPD';
            }
        }

        return $payload;
    }

    public function test_catalog_resources_support_full_crud(): void
    {
        foreach (self::crudProvider() as $label => [[$prefix, $resource, $payload]]) {
            $payload = $this->resolvePayload($payload);

            $store = $this->postJson("/api/{$prefix}/{$resource}", $payload)
                ->assertStatus(201)
                ->assertJsonPath('success', true);

            $id = $store->json('data.id');

            $this->getJson("/api/{$prefix}/{$resource}/{$id}")
                ->assertStatus(200)
                ->assertJsonPath('data.id', $id);

            $this->putJson("/api/{$prefix}/{$resource}/{$id}", $this->updatePayload($payload))
                ->assertStatus(200)
                ->assertJsonPath('success', true);

            $this->getJson("/api/{$prefix}/{$resource}")
                ->assertStatus(200)
                ->assertJsonPath('success', true);

            $this->deleteJson("/api/{$prefix}/{$resource}/{$id}")
                ->assertStatus(200)
                ->assertJsonPath('success', true);
        }
    }

    public function test_unknown_resource_segment_404s(): void
    {
        $this->getJson('/api/service-catalog/does-not-exist')
            ->assertStatus(404);
    }

    public function test_tenant_isolation_prevents_cross_tenant_access(): void
    {
        $template = $this->postJson('/api/service-catalog/service-templates', ['name' => 'Secret Fiber', 'service_type' => 'fiber'])
            ->json('data');

        $otherTenant = Tenant::factory()->create(['status' => 'active']);
        $intruder = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $intruder->assignRole('super_admin');
        Tenant::setCurrent($otherTenant);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/service-catalog/service-templates/{$template['id']}")
            ->assertStatus(404);

        $this->putJson("/api/service-catalog/service-templates/{$template['id']}", ['name' => 'Hacked'])
            ->assertStatus(404);

        $this->deleteJson("/api/service-catalog/service-templates/{$template['id']}")
            ->assertStatus(404);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/service-catalog/service-templates', ['name' => 'Nope'])
            ->assertStatus(403);

        $this->getJson('/api/service-catalog/service-templates')
            ->assertStatus(403);
    }

    public function test_audit_log_is_recorded_on_catalog_mutation(): void
    {
        $this->postJson('/api/inventory-ext/warehouses', ['name' => 'Audit WH'])->assertStatus(201);

        $this->assertDatabaseHas('system_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'warehouse.created',
            'model' => 'Warehouse',
        ]);
    }
}
