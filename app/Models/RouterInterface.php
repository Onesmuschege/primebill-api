<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RouterInterface extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'router_id',
        'name',
        'type',
        'status',
        'ip_address',
        'mac_address',
        'vlan_id',
        'description',
        'metrics',
        'configuration',
    ];

    protected $casts = [
        'metrics' => 'array',
        'configuration' => 'array',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
