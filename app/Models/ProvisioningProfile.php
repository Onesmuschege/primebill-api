<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class ProvisioningProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'provisioning_rules',
        'radius_attributes',
        'router_commands',
        'ip_allocation_rules',
        'vlan_rules',
        'qos_rules',
        'validation_rules',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'provisioning_rules' => 'array',
        'radius_attributes' => 'array',
        'router_commands' => 'array',
        'ip_allocation_rules' => 'array',
        'vlan_rules' => 'array',
        'qos_rules' => 'array',
        'validation_rules' => 'array',
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
