<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RouterTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'router_type',
        'base_configuration',
        'interface_configurations',
        'firewall_rules',
        'queue_rules',
        'routing_configuration',
        'variables',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'base_configuration' => 'array',
        'interface_configurations' => 'array',
        'firewall_rules' => 'array',
        'queue_rules' => 'array',
        'routing_configuration' => 'array',
        'variables' => 'array',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
