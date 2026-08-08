<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Traits\Encryptable;
use App\Traits\LogsAudit;

class Olt extends Model
{
    use HasFactory, BelongsToTenant, Encryptable, LogsAudit;

    protected string $auditAlias = 'Olt';

    protected $fillable = [
        'name', 'vendor', 'model', 'ip_address', 'username', 'password',
        'snmp_community', 'status', 'location', 'location_lat', 'location_lng',
        'last_seen', 'tenant_id',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'last_seen' => 'datetime',
        'location_lat' => 'decimal:7',
        'location_lng' => 'decimal:7',
    ];

    protected $encryptable = ['password', 'snmp_community'];

    public function ponPorts()
    {
        return $this->hasMany(PonPort::class);
    }

    public function onts()
    {
        return $this->hasMany(Ont::class);
    }
}
