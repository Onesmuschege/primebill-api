<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\IpReservation;
use App\Models\IpSubnet;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpamTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['status' => 'active']);
        Tenant::setCurrent($this->tenant);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('super_admin');
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    protected function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    protected function createPool(array $overrides = []): IpPool
    {
        return IpPool::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name'      => 'LAN Pool',
            'family'    => 'ipv4',
            'network'   => '192.168.10.0',
            'prefix'    => 24,
            'is_public' => false,
            'status'    => 'active',
        ], $overrides));
    }

    public function test_can_create_pool(): void
    {
        $response = $this->postJson('/api/ipam/pools', [
            'name'      => 'Office LAN',
            'network'   => '10.0.0.0',
            'prefix'    => 24,
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'Office LAN');

        $this->assertDatabaseHas('ip_pools', [
            'network' => '10.0.0.0',
            'prefix'  => 24,
        ]);
    }

    public function test_can_list_pools(): void
    {
        $this->createPool();

        $response = $this->getJson('/api/ipam/pools', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(1, 'data.data');
    }

    public function test_can_create_subnet(): void
    {
        $pool = $this->createPool();

        $response = $this->postJson('/api/ipam/subnets', [
            'ip_pool_id' => $pool->id,
            'name'       => 'Staff Subnet',
            'cidr'       => '192.168.10.0/24',
            'network'    => '192.168.10.0',
            'prefix'     => 24,
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'Staff Subnet');
    }

    public function test_can_allocate_ip(): void
    {
        $pool = $this->createPool(['network' => '192.168.10.0']);

        $response = $this->postJson('/api/ipam/allocations', [
            'ip_pool_id' => $pool->id,
            'ip_address' => '192.168.10.10',
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.ip_address', '192.168.10.10');

        $this->assertDatabaseHas('ip_allocations', [
            'ip_address' => '192.168.10.10',
            'status'     => 'allocated',
        ]);
    }

    public function test_cannot_allocate_duplicate_address(): void
    {
        $pool = $this->createPool(['network' => '192.168.10.0']);

        $this->postJson('/api/ipam/allocations', [
            'ip_pool_id' => $pool->id,
            'ip_address' => '192.168.10.10',
        ], $this->headers());

        $response = $this->postJson('/api/ipam/allocations', [
            'ip_pool_id' => $pool->id,
            'ip_address' => '192.168.10.10',
        ], $this->headers());

        $response->assertStatus(422);
    }

    public function test_can_release_allocation(): void
    {
        $pool = $this->createPool(['network' => '192.168.10.0']);

        $allocation = IpAllocation::create([
            'tenant_id'      => $this->tenant->id,
            'ip_pool_id'     => $pool->id,
            'ip_address'     => '192.168.10.20',
            'family'         => 'ipv4',
            'status'         => 'allocated',
            'allocated_at'   => now(),
        ]);

        $response = $this->postJson("/api/ipam/allocations/{$allocation->id}/release", [
            'reason' => 'Client moved',
        ], $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseHas('ip_allocations', [
            'id'     => $allocation->id,
            'status' => 'released',
        ]);
    }

    public function test_can_create_reservation(): void
    {
        $pool = $this->createPool(['network' => '192.168.10.0']);

        $response = $this->postJson('/api/ipam/reservations', [
            'ip_pool_id' => $pool->id,
            'ip_address' => '192.168.10.50',
            'mac_address' => '00:11:22:33:44:55',
            'hostname'    => 'printer',
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.ip_address', '192.168.10.50');
    }

    public function test_cannot_allocate_reserved_address(): void
    {
        $pool = $this->createPool(['network' => '192.168.10.0']);

        IpReservation::create([
            'tenant_id'  => $this->tenant->id,
            'ip_pool_id' => $pool->id,
            'ip_address' => '192.168.10.60',
            'family'     => 'ipv4',
        ]);

        $response = $this->postJson('/api/ipam/allocations', [
            'ip_pool_id' => $pool->id,
            'ip_address' => '192.168.10.60',
        ], $this->headers());

        $response->assertStatus(422);
    }

    public function test_can_create_vlan(): void
    {
        $response = $this->postJson('/api/ipam/vlans', [
            'vlan_id' => 10,
            'name'    => 'Management',
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'Management');
    }

    public function test_cannot_duplicate_vlan_id(): void
    {
        $this->postJson('/api/ipam/vlans', [
            'vlan_id' => 20,
            'name'    => 'First',
        ], $this->headers());

        $response = $this->postJson('/api/ipam/vlans', [
            'vlan_id' => 20,
            'name'    => 'Duplicate',
        ], $this->headers());

        $response->assertStatus(422);
    }

    public function test_tenant_isolation_for_allocations(): void
    {
        $otherTenant = Tenant::factory()->create();
        $pool = $this->createPool(['network' => '192.168.10.0']);

        IpAllocation::create([
            'tenant_id'  => $this->tenant->id,
            'ip_pool_id' => $pool->id,
            'ip_address' => '192.168.10.30',
            'family'     => 'ipv4',
            'status'     => 'allocated',
            'allocated_at' => now(),
        ]);

        // A user in another tenant must not see this allocation.
        Tenant::setCurrent($otherTenant);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser->assignRole('super_admin');
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        $response = $this->getJson('/api/ipam/pools', [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonCount(0, 'data.data');
    }

    public function test_summary_endpoint(): void
    {
        $pool = $this->createPool();
        IpAllocation::create([
            'tenant_id'    => $this->tenant->id,
            'ip_pool_id'   => $pool->id,
            'ip_address'   => '192.168.10.5',
            'family'       => 'ipv4',
            'status'       => 'allocated',
            'allocated_at' => now(),
        ]);

        $response = $this->getJson('/api/ipam/summary', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.pools', 1)
                 ->assertJsonPath('data.allocated', 1);
    }
}
