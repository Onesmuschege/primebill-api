<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class ServiceTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'service_type',
        'plan_defaults',
        'provisioning_profile',
        'qos_profile',
        'ip_requirements',
        'vlan_requirements',
        'radius_profile',
        'router_profile',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'plan_defaults' => 'array',
        'provisioning_profile' => 'array',
        'qos_profile' => 'array',
        'ip_requirements' => 'array',
        'vlan_requirements' => 'array',
        'radius_profile' => 'array',
        'router_profile' => 'array',
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
