<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DhcpLease;
use App\Models\DhcpPool;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\IpReservation;
use App\Models\IpSubnet;
use App\Models\Vlan;
use App\Models\VlanAssignment;
use App\Services\Ipam\IpamService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IpamController extends Controller
{
    use ApiResponse;

    public function __construct(protected IpamService $ipam)
    {
    }

    // ─── Pools ────────────────────────────────────────────────────────────

    public function indexPools(Request $request)
    {
        $pools = $this->ipam->pools($request->only(['family', 'status', 'search', 'per_page']));

        return $this->success($pools);
    }

    public function storePool(Request $request)
    {
        $data = $request->validate([
            'name'           => 'required|string|max:191',
            'family'         => 'sometimes|in:ipv4,ipv6',
            'network'        => 'required|ip',
            'prefix'         => 'required|integer|min:1|max:128',
            'gateway'        => 'sometimes|nullable|ip',
            'dns_primary'    => 'sometimes|nullable|ip',
            'dns_secondary'  => 'sometimes|nullable|ip',
            'is_public'      => 'sometimes|boolean',
            'status'         => 'sometimes|in:active,disabled,exhausted',
            'description'    => 'sometimes|nullable|string',
            'vlan_id'        => 'sometimes|nullable|exists:vlans,id',
            'router_id'      => 'sometimes|nullable|exists:routers,id',
        ]);

        $pool = IpPool::create($data);

        return $this->success($pool, 'IP pool created', 201);
    }

    public function showPool(IpPool $pool)
    {
        $pool->load(['vlan', 'router', 'subnets']);

        return $this->success($pool);
    }

    public function updatePool(Request $request, IpPool $pool)
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:191',
            'gateway'       => 'sometimes|nullable|ip',
            'dns_primary'   => 'sometimes|nullable|ip',
            'dns_secondary' => 'sometimes|nullable|ip',
            'is_public'     => 'sometimes|boolean',
            'status'        => 'sometimes|in:active,disabled,exhausted',
            'description'   => 'sometimes|nullable|string',
            'vlan_id'       => 'sometimes|nullable|exists:vlans,id',
            'router_id'     => 'sometimes|nullable|exists:routers,id',
        ]);

        $pool->update($data);

        return $this->success($pool, 'IP pool updated');
    }

    public function destroyPool(IpPool $pool)
    {
        $pool->delete();

        return $this->success(null, 'IP pool deleted');
    }

    // ─── Subnets ──────────────────────────────────────────────────────────

    public function indexSubnets(Request $request)
    {
        $subnets = $this->ipam->subnets($request->only(['ip_pool_id', 'family', 'search', 'per_page']));

        return $this->success($subnets);
    }

    public function storeSubnet(Request $request)
    {
        $data = $request->validate([
            'ip_pool_id'   => 'sometimes|nullable|exists:ip_pools,id',
            'name'         => 'required|string|max:191',
            'family'       => 'sometimes|in:ipv4,ipv6',
            'cidr'         => 'required|string|max:64',
            'network'      => 'required|ip',
            'prefix'       => 'required|integer|min:1|max:128',
            'gateway'      => 'sometimes|nullable|ip',
            'is_public'    => 'sometimes|boolean',
            'status'       => 'sometimes|in:active,disabled',
            'description'  => 'sometimes|nullable|string',
            'vlan_id'      => 'sometimes|nullable|exists:vlans,id',
        ]);

        $subnet = IpSubnet::create($data);

        return $this->success($subnet, 'Subnet created', 201);
    }

    public function showSubnet(IpSubnet $subnet)
    {
        $subnet->load(['pool', 'vlan', 'allocations']);

        return $this->success($subnet);
    }

    public function updateSubnet(Request $request, IpSubnet $subnet)
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:191',
            'gateway'     => 'sometimes|nullable|ip',
            'is_public'   => 'sometimes|boolean',
            'status'      => 'sometimes|in:active,disabled',
            'description' => 'sometimes|nullable|string',
            'vlan_id'     => 'sometimes|nullable|exists:vlans,id',
        ]);

        $subnet->update($data);

        return $this->success($subnet, 'Subnet updated');
    }

    public function destroySubnet(IpSubnet $subnet)
    {
        $subnet->delete();

        return $this->success(null, 'Subnet deleted');
    }

    // ─── Allocations ──────────────────────────────────────────────────────

    public function indexAllocations(Request $request)
    {
        $allocations = $this->ipam->allocations($request->only(['status', 'ip_pool_id', 'client_id', 'search', 'per_page']));

        return $this->success($allocations);
    }

    public function storeAllocation(Request $request)
    {
        $data = $request->validate([
            'ip_pool_id'        => 'required_without:ip_subnet_id|nullable|exists:ip_pools,id',
            'ip_subnet_id'      => 'required_without:ip_pool_id|nullable|exists:ip_subnets,id',
            'ip_address'        => 'sometimes|nullable|ip',
            'client_id'         => 'sometimes|nullable|exists:clients,id',
            'client_account_id' => 'sometimes|nullable|exists:client_accounts,id',
            'mac_address'       => 'sometimes|nullable|string|max:17',
            'hostname'          => 'sometimes|nullable|string|max:191',
            'description'       => 'sometimes|nullable|string',
        ]);

        try {
            $allocation = $this->ipam->allocate($data, $data['ip_address'] ?? null);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($allocation->load(['pool', 'subnet', 'client', 'clientAccount', 'vlan']), 'IP allocated', 201);
    }

    public function releaseAllocation(Request $request, IpAllocation $allocation)
    {
        $this->ipam->release($allocation, $request->input('reason'));

        return $this->success($allocation->refresh(), 'IP released');
    }

    public function allocationHistory(Request $request, IpAllocation $allocation)
    {
        $history = $allocation->history()
            ->with(['client', 'user'])
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 15));

        return $this->success($history);
    }

    // ─── Reservations ─────────────────────────────────────────────────────

    public function indexReservations(Request $request)
    {
        $reservations = IpReservation::query()
            ->with(['pool', 'subnet', 'client', 'clientAccount'])
            ->when($request->input('ip_pool_id'), fn ($q, $v) => $q->where('ip_pool_id', $v))
            ->when($request->input('client_id'), fn ($q, $v) => $q->where('client_id', $v))
            ->when($request->input('search'), function ($q, $v) {
                $q->where('ip_address', 'like', "%{$v}%")
                  ->orWhere('mac_address', 'like', "%{$v}%")
                  ->orWhere('hostname', 'like', "%{$v}%");
            })
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 15));

        return $this->success($reservations);
    }

    public function storeReservation(Request $request)
    {
        $data = $request->validate([
            'ip_pool_id'        => 'required_without:ip_subnet_id|nullable|exists:ip_pools,id',
            'ip_subnet_id'      => 'required_without:ip_pool_id|nullable|exists:ip_subnets,id',
            'ip_address'        => 'required|ip',
            'mac_address'       => 'sometimes|nullable|string|max:17',
            'hostname'          => 'sometimes|nullable|string|max:191',
            'client_id'         => 'sometimes|nullable|exists:clients,id',
            'client_account_id' => 'sometimes|nullable|exists:client_accounts,id',
            'description'       => 'sometimes|nullable|string',
        ]);

        try {
            $reservation = $this->ipam->reserve($data);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), null, 422);
        }

        return $this->success($reservation->load(['pool', 'subnet', 'client', 'clientAccount']), 'Reservation created', 201);
    }

    public function destroyReservation(IpReservation $reservation)
    {
        $reservation->delete();

        return $this->success(null, 'Reservation deleted');
    }

    // ─── DHCP ─────────────────────────────────────────────────────────────

    public function indexDhcpPools(Request $request)
    {
        $pools = DhcpPool::query()
            ->with(['ipPool', 'ipSubnet'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->paginate($request->input('per_page', 15));

        return $this->success($pools);
    }

    public function storeDhcpPool(Request $request)
    {
        $data = $request->validate([
            'ip_pool_id'     => 'sometimes|nullable|exists:ip_pools,id',
            'ip_subnet_id'   => 'sometimes|nullable|exists:ip_subnets,id',
            'name'           => 'required|string|max:191',
            'range_start'    => 'required|ip',
            'range_end'      => 'required|ip',
            'gateway'        => 'sometimes|nullable|ip',
            'dns_primary'    => 'sometimes|nullable|ip',
            'dns_secondary'  => 'sometimes|nullable|ip',
            'lease_time'     => 'sometimes|string|max:32',
            'status'         => 'sometimes|in:active,disabled',
            'description'    => 'sometimes|nullable|string',
        ]);

        $pool = DhcpPool::create($data);

        return $this->success($pool, 'DHCP pool created', 201);
    }

    public function indexDhcpLeases(Request $request)
    {
        $leases = DhcpLease::query()
            ->with('dhcpPool')
            ->when($request->input('dhcp_pool_id'), fn ($q, $v) => $q->where('dhcp_pool_id', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('search'), function ($q, $v) {
                $q->where('ip_address', 'like', "%{$v}%")
                  ->orWhere('mac_address', 'like', "%{$v}%")
                  ->orWhere('hostname', 'like', "%{$v}%");
            })
            ->orderByDesc('lease_start')
            ->paginate($request->input('per_page', 15));

        return $this->success($leases);
    }

    public function storeDhcpLease(Request $request)
    {
        $data = $request->validate([
            'dhcp_pool_id' => 'required|exists:dhcp_pools,id',
            'ip_address'   => 'required|ip',
            'mac_address'  => 'required|string|max:17',
            'hostname'     => 'sometimes|nullable|string|max:191',
            'lease_start'  => 'sometimes|nullable|date',
            'lease_end'    => 'sometimes|nullable|date',
            'status'       => 'sometimes|in:active,expired,released',
        ]);

        $lease = DhcpLease::create($data);

        return $this->success($lease, 'DHCP lease created', 201);
    }

    // ─── VLANs ────────────────────────────────────────────────────────────

    public function indexVlans(Request $request)
    {
        $vlans = $this->ipam->vlans($request->only(['status', 'search', 'per_page']));

        return $this->success($vlans);
    }

public function storeVlan(Request $request)
    {
        $data = $request->validate([
            'vlan_id'     => 'required|integer|min:1|max:4094|unique:vlans,vlan_id',
            'name'        => 'required|string|max:191',
            'description' => 'sometimes|nullable|string',
            'router_id'   => 'sometimes|nullable|exists:routers,id',
            'status'      => 'sometimes|in:active,disabled',
        ]);

        $vlan = Vlan::create($data);

        return $this->success($vlan, 'VLAN created', 201);
    }

    public function showVlan(Vlan $vlan)
    {
        $vlan->load(['router', 'pools', 'subnets', 'assignments']);

        return $this->success($vlan);
    }

    public function updateVlan(Request $request, Vlan $vlan)
    {
        $data = $request->validate([
            'vlan_id'     => 'sometimes|integer|min:1|max:4094',
            'name'        => 'sometimes|string|max:191',
            'description' => 'sometimes|nullable|string',
            'router_id'   => 'sometimes|nullable|exists:routers,id',
            'status'      => 'sometimes|in:active,disabled',
        ]);

        $vlan->update($data);

        return $this->success($vlan, 'VLAN updated');
    }

    public function destroyVlan(Vlan $vlan)
    {
        $vlan->delete();

        return $this->success(null, 'VLAN deleted');
    }

    public function assignVlan(Request $request)
    {
        $data = $request->validate([
            'vlan_id'        => 'required|exists:vlans,id',
            'assignable_type' => 'required|string|max:191',
            'assignable_id'   => 'required|integer',
            'is_trunk'        => 'sometimes|boolean',
            'trunk_ports'     => 'sometimes|nullable|string|max:191',
            'description'     => 'sometimes|nullable|string',
        ]);

        $assignment = VlanAssignment::create($data);

        return $this->success($assignment->load('vlan'), 'VLAN assigned', 201);
    }

    // ─── Summary ──────────────────────────────────────────────────────────

    public function summary()
    {
        $pools = IpPool::count();
        $subnets = IpSubnet::count();
        $allocated = IpAllocation::where('status', 'allocated')->count();
        $reserved = IpReservation::count();
        $vlans = Vlan::count();
        $leases = DhcpLease::where('status', 'active')->count();

        return $this->success([
            'pools'      => $pools,
            'subnets'    => $subnets,
            'allocated'  => $allocated,
            'reserved'   => $reserved,
            'vlans'      => $vlans,
            'active_dhcp_leases' => $leases,
        ]);
    }
}
