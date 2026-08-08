<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class IpPool extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'IpPool';

    protected $fillable = [
        'name', 'family', 'network', 'prefix', 'gateway',
        'dns_primary', 'dns_secondary', 'is_public', 'status',
        'description', 'vlan_id', 'router_id',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function subnets()
    {
        return $this->hasMany(IpSubnet::class);
    }

    public function allocations()
    {
        return $this->hasMany(IpAllocation::class);
    }

    public function reservations()
    {
        return $this->hasMany(IpReservation::class);
    }

    public function dhcpPools()
    {
        return $this->hasMany(DhcpPool::class);
    }
}
