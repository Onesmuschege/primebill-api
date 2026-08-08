<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class ClientAccountAddon extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_account_id',
        'service_addon_id',
        'status',
        'price',
        'currency',
        'billing_type',
        'configuration',
        'activated_at',
        'suspended_at',
        'cancelled_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'configuration' => 'array',
        'activated_at' => 'date',
        'suspended_at' => 'date',
        'cancelled_at' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function serviceAddon(): BelongsTo
    {
        return $this->belongsTo(ServiceAddon::class);
    }

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
        return $this->status === 'active';
    }
}
