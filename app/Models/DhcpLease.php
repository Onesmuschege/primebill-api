<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class DhcpLease extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'DhcpLease';

    protected $fillable = [
        'dhcp_pool_id', 'ip_address', 'mac_address', 'hostname',
        'lease_start', 'lease_end', 'status',
    ];

    protected $casts = [
        'lease_start' => 'datetime',
        'lease_end'   => 'datetime',
    ];

    public function dhcpPool()
    {
        return $this->belongsTo(DhcpPool::class);
    }
}
