<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class IpAllocation extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'IpAllocation';

    protected $fillable = [
        'ip_pool_id', 'ip_subnet_id', 'ip_address', 'family', 'status',
        'client_id', 'client_account_id', 'vlan_id', 'mac_address',
        'hostname', 'description', 'allocated_at', 'released_at',
    ];

    protected $casts = [
        'allocated_at' => 'datetime',
        'released_at'  => 'datetime',
    ];

    public function pool()
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    public function subnet()
    {
        return $this->belongsTo(IpSubnet::class, 'ip_subnet_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function clientAccount()
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function vlan()
    {
        return $this->belongsTo(Vlan::class);
    }

    public function history()
    {
        return $this->hasMany(IpAllocationHistory::class);
    }
}
