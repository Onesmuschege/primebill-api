<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class Vlan extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'Vlan';

    protected $fillable = [
        'vlan_id', 'name', 'description', 'router_id', 'status',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function pools()
    {
        return $this->hasMany(IpPool::class);
    }

    public function subnets()
    {
        return $this->hasMany(IpSubnet::class);
    }

    public function assignments()
    {
        return $this->hasMany(VlanAssignment::class);
    }
}
