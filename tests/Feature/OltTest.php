<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PonPort;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 — Fiber / OLT Management feature tests.
 *
 * Covers OLT/PON/ONT CRUD, ONT registration + signal polling via the vendor
 * adapter mock, and tenant isolation.
 */
class OltTest extends TestCase
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

        // Ensure the mock OLT driver is used for deterministic tests.
        config(['network.olt_driver' => 'mock']);
    }

    protected function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    protected function createOlt(array $overrides = []): Olt
    {
        return Olt::create(array_merge([
            'tenant_id'  => $this->tenant->id,
            'name'       => 'Main OLT',
            'vendor'     => 'huawei',
            'model'      => 'MA5608T',
            'ip_address' => '192.168.100.4',
            'password'   => 'secret',
            'status'     => 'online',
        ], $overrides));
    }

    protected function createPonPort(Olt $olt, array $overrides = []): PonPort
    {
        return PonPort::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'olt_id'    => $olt->id,
            'name'      => 'gpon-0/1/0',
            'technology'=> 'gpon',
            'status'    => 'active',
            'max_onts'  => 64,
        ], $overrides));
    }

    public function test_can_create_olt(): void
    {
        $response = $this->postJson('/api/olts', [
            'name'       => 'Downtown OLT',
            'vendor'     => 'zte',
            'ip_address' => '10.0.0.5',
            'password'   => 'supersecret',
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'Downtown OLT');

        $this->assertDatabaseHas('olts', [
            'name'       => 'Downtown OLT',
            'ip_address' => '10.0.0.5',
        ]);
    }

    public function test_can_list_olts(): void
    {
        $this->createOlt();

        $response = $this->getJson('/api/olts', $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonCount(1, 'data.data');
    }

    public function test_can_create_pon_port(): void
    {
        $olt = $this->createOlt();

        $response = $this->postJson("/api/olts/{$olt->id}/pon-ports", [
            'name'   => 'gpon-0/0/1',
            'max_onts' => 128,
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'gpon-0/0/1');

        $this->assertDatabaseHas('pon_ports', [
            'olt_id' => $olt->id,
            'name'   => 'gpon-0/0/1',
        ]);
    }

    public function test_can_register_ont(): void
    {
        $olt = $this->createOlt();
        $port = $this->createPonPort($olt);

        $response = $this->postJson("/api/olts/{$olt->id}/onts", [
            'pon_port_id' => $port->id,
            'serial'      => 'HWTC12345678',
            'mac_address' => '00:1A:2B:3C:4D:5E',
            'vendor'      => 'huawei',
            'model'       => 'HG8245H',
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.serial', 'HWTC12345678');

        $this->assertDatabaseHas('onts', [
            'serial' => 'HWTC12345678',
            'status' => 'provisioning',
        ]);

        // PON port registered_onts counter should increment.
        $this->assertDatabaseHas('pon_ports', [
            'id'               => $port->id,
            'registered_onts'  => 1,
        ]);
    }

    public function test_can_link_ont_to_client_account(): void
    {
        $olt = $this->createOlt();
        $port = $this->createPonPort($olt);

        $client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
        $account = ClientAccount::factory()->create([
            'tenant_id' => $this->tenant->id,
            'client_id' => $client->id,
        ]);

        $response = $this->postJson("/api/olts/{$olt->id}/onts", [
            'pon_port_id'       => $port->id,
            'serial'            => 'ZTEABC1234567',
            'client_account_id' => $account->id,
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('data.client_account_id', $account->id);

        $this->assertDatabaseHas('onts', [
            'client_account_id' => $account->id,
            'serial'            => 'ZTEABC1234567',
        ]);
    }

    public function test_can_poll_ont_signal(): void
    {
        $olt = $this->createOlt();
        $port = $this->createPonPort($olt);

        $ont = Ont::create([
            'tenant_id'   => $this->tenant->id,
            'olt_id'      => $olt->id,
            'pon_port_id' => $port->id,
            'serial'      => 'VSOLEZ9876543',
            'status'      => 'offline',
        ]);

        $response = $this->postJson("/api/olts/{$olt->id}/poll-signal", [], $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $ont->refresh();
        // The mock adapter returns deterministic readings; ONT should be online.
        $this->assertEquals('online', $ont->status);
        $this->assertNotNull($ont->rx_signal);
        $this->assertNotNull($ont->tx_signal);
    }

    public function test_can_show_ont(): void
    {
        $olt = $this->createOlt();
        $port = $this->createPonPort($olt);

        $ont = Ont::create([
            'tenant_id'   => $this->tenant->id,
            'olt_id'      => $olt->id,
            'pon_port_id' => $port->id,
            'serial'      => 'FSBN1234567890',
            'vendor'      => 'fiberhome',
            'status'      => 'online',
        ]);

        $response = $this->getJson("/api/onts/{$ont->id}", $this->headers());

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.serial', 'FSBN1234567890');
    }

    public function test_tenant_isolation_for_olts(): void
    {
        $this->createOlt();

        $otherTenant = Tenant::factory()->create();
        Tenant::setCurrent($otherTenant);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherUser->assignRole('super_admin');
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        $response = $this->getJson('/api/olts', [
            'Authorization' => "Bearer {$otherToken}",
        ]);

        $response->assertStatus(200)
                 ->assertJsonCount(0, 'data.data');
    }

    public function test_can_create_fiber_route(): void
    {
        $response = $this->postJson('/api/fiber/routes', [
            'name'        => 'Central to Node 1',
            'source'      => 'Central Office',
            'destination' => 'Node 1',
            'length_km'   => 3.5,
            'cable_type'  => 'underground',
        ], $this->headers());

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'Central to Node 1');

        $this->assertDatabaseHas('fiber_routes', [
            'name'        => 'Central to Node 1',
            'length_km'   => 3.5,
        ]);
    }

    public function test_can_create_splitter_and_cabinet(): void
    {
        $splitter = $this->postJson('/api/fiber/splitters', [
            'name'        => 'Splitter A',
            'split_ratio' => '1:16',
        ], $this->headers());

        $splitter->assertStatus(201)
                 ->assertJsonPath('data.split_ratio', '1:16');

        $cabinet = $this->postJson('/api/fiber/cabinets', [
            'name'   => 'Cabinet 1',
            'type'   => 'distribution',
        ], $this->headers());

        $cabinet->assertStatus(201)
                 ->assertJsonPath('data.type', 'distribution');
    }

    public function test_ont_signal_polling_command(): void
    {
        $olt = $this->createOlt();
        $port = $this->createPonPort($olt);

        Ont::create([
            'tenant_id'   => $this->tenant->id,
            'olt_id'      => $olt->id,
            'pon_port_id' => $port->id,
            'serial'      => 'MOCKONT001234',
            'status'      => 'offline',
        ]);

        $this->artisan('fiber:poll-ont-signal')
            ->assertSuccessful();
    }
}
