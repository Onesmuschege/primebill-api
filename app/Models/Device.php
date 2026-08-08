<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class Device extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'Device';

    protected $fillable = [
        'client_id', 'client_account_id', 'nas_id',
        'mac_address', 'ip_address', 'device_name', 'device_type',
        'vendor', 'first_seen_at', 'last_seen_at', 'status',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function account()
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    public function nas()
    {
        return $this->belongsTo(Router::class, 'nas_id');
    }
}
