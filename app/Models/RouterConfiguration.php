<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RouterConfiguration extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'router_id',
        'version',
        'label',
        'configuration',
        'interfaces',
        'firewall_rules',
        'queue_rules',
        'routing_rules',
        'variables',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'configuration' => 'array',
        'interfaces' => 'array',
        'firewall_rules' => 'array',
        'queue_rules' => 'array',
        'routing_rules' => 'array',
        'variables' => 'array',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
