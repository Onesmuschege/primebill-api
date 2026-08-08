<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class ServiceAddon extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'description',
        'category',
        'price',
        'currency',
        'billing_type',
        'configuration',
        'provisioning_requirements',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'configuration' => 'array',
        'provisioning_requirements' => 'array',
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

    public function accountAddons(): HasMany
    {
        return $this->hasMany(ClientAccountAddon::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
