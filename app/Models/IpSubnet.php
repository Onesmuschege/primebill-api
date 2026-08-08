<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class IpSubnet extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'IpSubnet';

    protected $fillable = [
        'ip_pool_id', 'name', 'family', 'cidr', 'network', 'prefix',
        'gateway', 'is_public', 'status', 'description', 'vlan_id',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function pool()
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }

    public function allocations()
    {
        return $this->hasMany(IpAllocation::class);
    }

    public function reservations()
    {
        return $this->hasMany(IpReservation::class);
    }
}
