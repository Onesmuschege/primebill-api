<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class Ont extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'Ont';

    protected $fillable = [
        'olt_id', 'pon_port_id', 'serial', 'mac_address', 'vendor',
        'model', 'firmware', 'rx_signal', 'tx_signal', 'status',
        'last_seen', 'client_account_id', 'tenant_id',
    ];

    protected $casts = [
        'rx_signal' => 'decimal:2',
        'tx_signal' => 'decimal:2',
        'last_seen' => 'datetime',
    ];

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    public function ponPort()
    {
        return $this->belongsTo(PonPort::class);
    }

    public function clientAccount()
    {
        return $this->belongsTo(ClientAccount::class);
    }
}
