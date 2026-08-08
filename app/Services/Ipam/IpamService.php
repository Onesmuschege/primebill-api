<?php

namespace App\Services\Ipam;

use App\Models\IpAllocation;
use App\Models\IpAllocationHistory;
use App\Models\IpPool;
use App\Models\IpReservation;
use App\Models\IpSubnet;
use App\Models\Vlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enterprise IPAM service.
 *
 * Provides the allocation engine with conflict detection, free-address
 * discovery, reservations, release/return, and VLAN helpers. All operations
 * are tenant-scoped via the BelongsToTenant global scope.
 */
class IpamService
{
    /**
     * List pools with utilization.
     */
    public function pools(array $filters = [])
    {
        $query = IpPool::query()
            ->with(['vlan', 'router'])
            ->withCount('allocations');

        if (!empty($filters['family'])) {
            $query->where('family', $filters['family']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%");
        }

        return $query->orderBy('name')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * List subnets.
     */
    public function subnets(array $filters = [])
    {
        $query = IpSubnet::query()
            ->with(['pool', 'vlan'])
            ->withCount('allocations');

        if (!empty($filters['ip_pool_id'])) {
            $query->where('ip_pool_id', $filters['ip_pool_id']);
        }
        if (!empty($filters['family'])) {
            $query->where('family', $filters['family']);
        }
        if (!empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('cidr', 'like', "%{$filters['search']}%");
        }

        return $query->orderBy('network')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * List allocations.
     */
    public function allocations(array $filters = [])
    {
        $query = IpAllocation::query()
            ->with(['pool', 'subnet', 'client', 'clientAccount', 'vlan']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['ip_pool_id'])) {
            $query->where('ip_pool_id', $filters['ip_pool_id']);
        }
        if (!empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }
        if (!empty($filters['search'])) {
            $query->where('ip_address', 'like', "%{$filters['search']}%")
                  ->orWhere('hostname', 'like', "%{$filters['search']}%")
                  ->orWhere('mac_address', 'like', "%{$filters['search']}%");
        }

        return $query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * List VLANs.
     */
    public function vlans(array $filters = [])
    {
        $query = Vlan::query()->with('router');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('vlan_id', $filters['search']);
        }

        return $query->orderBy('vlan_id')->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Allocate a specific IP address, or find the next free one.
     *
     * @throws ValidationException
     */
    public function allocate(array $data, ?string $requestedAddress = null): IpAllocation
    {
        return DB::transaction(function () use ($data, $requestedAddress) {
            $pool = IpPool::findOrFail($data['ip_pool_id'] ?? $data['ip_subnet_id'] ?? null);

            $address = $this->resolveAddress($pool, $data, $requestedAddress);

            // Conflict detection — active allocation or reservation already using it.
            $this->assertAddressAvailable($address, $pool);

            $allocation = IpAllocation::create([
                'ip_pool_id'       => $data['ip_pool_id'] ?? null,
                'ip_subnet_id'     => $data['ip_subnet_id'] ?? null,
                'ip_address'       => $address,
                'family'           => $data['family'] ?? $pool->family,
                'status'           => 'allocated',
                'client_id'        => $data['client_id'] ?? null,
                'client_account_id'=> $data['client_account_id'] ?? null,
                'vlan_id'          => $data['vlan_id'] ?? $pool->vlan_id,
                'mac_address'      => $data['mac_address'] ?? null,
                'hostname'         => $data['hostname'] ?? null,
                'description'      => $data['description'] ?? null,
                'allocated_at'     => now(),
            ]);

            $this->recordHistory($allocation, 'allocated', $data);

            return $allocation;
        });
    }

    /**
     * Reserve an address for a client/device so it won't be auto-allocated.
     */
    public function reserve(array $data): IpReservation
    {
        return DB::transaction(function () use ($data) {
            $pool = IpPool::findOrFail($data['ip_pool_id'] ?? $data['ip_subnet_id'] ?? null);

            $address = $data['ip_address'];

            $this->assertAddressAvailable($address, $pool);

            $reservation = IpReservation::create([
                'ip_pool_id'       => $data['ip_pool_id'] ?? null,
                'ip_subnet_id'     => $data['ip_subnet_id'] ?? null,
                'ip_address'       => $address,
                'family'           => $data['family'] ?? $pool->family,
                'mac_address'      => $data['mac_address'] ?? null,
                'hostname'         => $data['hostname'] ?? null,
                'client_id'        => $data['client_id'] ?? null,
                'client_account_id'=> $data['client_account_id'] ?? null,
                'description'      => $data['description'] ?? null,
            ]);

            return $reservation;
        });
    }

    /**
     * Release an allocation back to the pool.
     */
    public function release(IpAllocation $allocation, string $reason = null): void
    {
        DB::transaction(function () use ($allocation, $reason) {
            $allocation->update([
                'status'      => 'released',
                'released_at' => now(),
            ]);

            $this->recordHistory($allocation, 'released', [
                'description' => $reason ?? 'Released back to pool',
            ]);
        });
    }

    /**
     * Find the next free address in a pool/subnet.
     */
    public function findFreeAddress(IpPool $pool, ?IpSubnet $subnet = null): ?string
    {
        [, $max] = $this->parseCidr($subnet?->cidr ?? $pool->network . '/' . $pool->prefix);

        $used = IpAllocation::where('status', 'allocated')
            ->where(fn ($q) => $q->where('ip_pool_id', $pool->id)->orWhere('ip_subnet_id', $subnet?->id))
            ->pluck('ip_address')
            ->merge(
                IpReservation::where(fn ($q) => $q->where('ip_pool_id', $pool->id)->orWhere('ip_subnet_id', $subnet?->id))
                    ->pluck('ip_address')
            )
            ->flip();

        for ($i = 1; $i < $max; $i++) {
            $candidate = long2ip($this->networkStart($pool->network) + $i);
            if (!$used->has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Resolve the target address (explicit or next-free).
     */
    protected function resolveAddress(IpPool $pool, array $data, ?string $requested): string
    {
        if ($requested) {
            return $requested;
        }

        $subnet = null;
        if (!empty($data['ip_subnet_id'])) {
            $subnet = IpSubnet::find($data['ip_subnet_id']);
        }

        $address = $this->findFreeAddress($pool, $subnet);

        if (!$address) {
            throw ValidationException::withMessages([
                'ip_pool_id' => 'No free addresses available in this pool.',
            ]);
        }

        return $address;
    }

    /**
     * Ensure an address is not already allocated or reserved.
     */
    protected function assertAddressAvailable(string $address, IpPool $pool): void
    {
        $conflict = IpAllocation::where('ip_address', $address)
            ->where('status', 'allocated')
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'ip_address' => "Address {$address} is already allocated.",
            ]);
        }

        $reserved = IpReservation::where('ip_address', $address)->exists();

        if ($reserved) {
            throw ValidationException::withMessages([
                'ip_address' => "Address {$address} is reserved and cannot be allocated.",
            ]);
        }
    }

    /**
     * Record allocation history.
     */
    protected function recordHistory(IpAllocation $allocation, string $action, array $data): void
    {
        IpAllocationHistory::create([
            'ip_allocation_id'   => $allocation->id,
            'action'             => $action,
            'ip_address'         => $allocation->ip_address,
            'client_id'          => $allocation->client_id,
            'client_account_id'  => $allocation->client_account_id,
            'user_id'            => auth()->id(),
            'description'        => $data['description'] ?? null,
        ]);
    }

    /**
     * Parse a CIDR into [networkLong, totalHosts].
     */
    protected function parseCidr(string $cidr): array
    {
        [$network, $prefix] = explode('/', $cidr);
        $networkLong = ip2long($network);
        $max = 1 << (32 - (int) $prefix);

        return [$networkLong, $max];
    }

    /**
     * Network start as a long integer.
     */
    protected function networkStart(string $network): int
    {
        return ip2long($network);
    }
}
