<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class CustomerEquipment extends Model
{
    use BelongsToTenant;

    protected $table = 'customer_equipment';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_account_id',
        'inventory_item_id',
        'type',
        'vendor',
        'model',
        'serial_number',
        'mac_address',
        'firmware_version',
        'status',
        'installation_date',
        'warranty_expiry',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'installation_date' => 'date',
        'warranty_expiry' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EquipmentAssignment::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(EquipmentHistory::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
