<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;

use Tests\TestCase;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Traits\Encryptable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class EncryptionTest extends TestCase
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

        // Grant the user access to view routers so the API route is reachable.
        $role = Role::findOrCreate('network-viewer');
        $role->givePermissionTo('view routers');
        $this->user->assignRole($role);
    }

    #[Test]
    public function router_password_is_encrypted_at_rest()
    {
        $router = Router::create([
            'name' => 'Test Router',
            'ip_address' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret123',
            'port' => 8728,
            'type' => 'mikrotik',
            'tenant_id' => $this->tenant->id,
        ]);

        $router->refresh();

        // The raw DB value should NOT be the plaintext password
        $rawPassword = \DB::table('routers')->where('id', $router->id)->value('password');
        $this->assertNotEquals('secret123', $rawPassword);

        // The attribute accessor should return the decrypted plaintext
        $this->assertEquals('secret123', $router->password);
    }

    #[Test]
    public function router_password_is_not_exposed_in_api_response()
    {
        $router = Router::create([
            'name' => 'Test Router',
            'ip_address' => '192.168.1.1',
            'username' => 'admin',
            'password' => 'secret123',
            'port' => 8728,
            'type' => 'mikrotik',
            'tenant_id' => $this->tenant->id,
        ]);

        $router->refresh();

        Sanctum::actingAs($this->user);
        $response = $this->getJson("/api/routers/{$router->id}");
        $response->assertStatus(200);

        // Password must never be serialized in API responses (hidden attribute)
        $this->assertArrayNotHasKey('password', $response->json());
    }

    #[Test]
    public function audit_log_masks_sensitive_data()
    {
        $service = app(\App\Services\Audit\AuditService::class);

        $log = $service->log(
            action: 'test.sensitive',
            model: 'Test',
            modelId: 1,
            oldValues: [],
            newValues: [
                'password' => 'super_secret',
                'api_key' => 'sk_test_abc123',
                'name' => 'Public Name',
                'email' => 'test@example.com',
            ],
        );

        $this->assertNotNull($log);
        $newValues = $log->new_values;

        $this->assertIsArray($newValues);
        $this->assertEquals('********', $newValues['password'] ?? 'NOT_MASKED');
        $this->assertEquals('********', $newValues['api_key'] ?? 'NOT_MASKED');
        $this->assertEquals('Public Name', $newValues['name'] ?? null);
        $this->assertEquals('test@example.com', $newValues['email'] ?? null);
    }
}
