<?php

namespace Tests\Feature\Fiber;

use App\Models\FiberConnection;
use App\Models\FiberRoute;
use App\Models\FiberSplitter;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\PonPort;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiberCapacityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class]);

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->givePermissionTo(['view fiber']);

        Tenant::setCurrent($this->tenant);
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_capacity_reports_olt_pon_ont_utilization_and_route_load(): void
    {
        $olt = Olt::create(['tenant_id' => $this->tenant->id, 'name' => 'OLT-East', 'ip_address' => '10.0.0.1', 'status' => 'online']);
        $portA = PonPort::create(['tenant_id' => $this->tenant->id, 'olt_id' => $olt->id, 'name' => 'gpon-0/1/0', 'max_onts' => 64, 'registered_onts' => 32, 'status' => 'active']);
        PonPort::create(['tenant_id' => $this->tenant->id, 'olt_id' => $olt->id, 'name' => 'gpon-0/1/1', 'max_onts' => 64, 'registered_onts' => 0, 'status' => 'inactive']);
        Ont::create(['tenant_id' => $this->tenant->id, 'olt_id' => $olt->id, 'pon_port_id' => $portA->id, 'serial' => 'HWTC0001', 'status' => 'online']);

        $route = FiberRoute::create(['tenant_id' => $this->tenant->id, 'name' => 'East Trunk', 'source' => 'OLT-East', 'destination' => 'Hub B', 'length_km' => 12.5, 'status' => 'active']);
        $connection = new FiberConnection();
        $connection->forceFill(['tenant_id' => $this->tenant->id, 'fiber_route_id' => $route->id, 'status' => 'active'])->save();

        FiberSplitter::create(['tenant_id' => $this->tenant->id, 'name' => 'SP-01', 'split_ratio' => '1:8', 'status' => 'active']);

        $data = $this->getJson('/api/fiber/capacity')->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['olts']);
        $this->assertSame(2, $data['summary']['pon_ports']);
        $this->assertSame(1, $data['summary']['registered_onts']);
        $this->assertSame(128, $data['summary']['max_ont_capacity']);
        $this->assertEquals(0.8, $data['summary']['ont_utilization_pct']); // 1 of 128

        $this->assertEquals(50.0, $data['olts'][0]['port_utilization_pct']); // 1 active of 2 ports
        $this->assertEquals(50.0, $data['pon_ports'][0]['utilization_pct']); // 32 of 64
        $this->assertSame(1, $data['routes'][0]['connection_count']);
        $this->assertSame('1:8', $data['splitters'][0]['split_ratio']);
    }

    public function test_capacity_can_be_filtered_to_a_single_olt(): void
    {
        $oltA = Olt::create(['tenant_id' => $this->tenant->id, 'name' => 'OLT-A', 'ip_address' => '10.0.0.2']);
        $oltB = Olt::create(['tenant_id' => $this->tenant->id, 'name' => 'OLT-B', 'ip_address' => '10.0.0.3']);
        PonPort::create(['tenant_id' => $this->tenant->id, 'olt_id' => $oltA->id, 'name' => 'gpon-0/1/0']);
        PonPort::create(['tenant_id' => $this->tenant->id, 'olt_id' => $oltB->id, 'name' => 'gpon-0/1/0']);

        $data = $this->getJson('/api/fiber/capacity?olt_id=' . $oltA->id)->assertOk()->json('data');

        $this->assertSame(1, $data['summary']['olts']);
        $this->assertCount(1, $data['pon_ports']);
        $this->assertSame('OLT-A', $data['olts'][0]['name']);
    }
}