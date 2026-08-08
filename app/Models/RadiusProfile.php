<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RadiusProfile extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'attributes',
        'bandwidth_limits',
        'session_parameters',
        'vlan_assignment',
        'qos_profile',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'attributes' => 'array',
        'bandwidth_limits' => 'array',
        'session_parameters' => 'array',
        'vlan_assignment' => 'array',
        'qos_profile' => 'array',
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
