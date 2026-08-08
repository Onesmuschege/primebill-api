<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class DhcpPool extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'DhcpPool';

    protected $fillable = [
        'ip_pool_id', 'ip_subnet_id', 'name', 'range_start', 'range_end',
        'gateway', 'dns_primary', 'dns_secondary', 'lease_time',
        'status', 'description',
    ];

    public function ipPool()
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    public function ipSubnet()
    {
        return $this->belongsTo(IpSubnet::class, 'ip_subnet_id');
    }

    public function leases()
    {
        return $this->hasMany(DhcpLease::class);
    }
}
