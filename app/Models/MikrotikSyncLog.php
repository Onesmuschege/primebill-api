<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class MikrotikSyncLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'client_account_id',
        'operation',
        'status',
        'router_ok',
        'radius_ok',
        'failure_reason',
        'attempts',
        'log_message',
    ];

    protected $casts = [
        'router_ok'  => 'boolean',
        'radius_ok'  => 'boolean',
        'attempts'   => 'integer',
    ];

    public function account()
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }
}
