<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class IpReservation extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'IpReservation';

    protected $fillable = [
        'ip_pool_id', 'ip_subnet_id', 'ip_address', 'family',
        'mac_address', 'hostname', 'client_id', 'client_account_id',
        'description',
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
}
