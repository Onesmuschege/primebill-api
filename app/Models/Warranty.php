<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class Warranty extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'customer_equipment_id',
        'client_id',
        'warranty_number',
        'provider',
        'status',
        'start_date',
        'end_date',
        'type',
        'coverage_details',
        'terms',
        'claim_date',
        'claim_notes',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'claim_date' => 'date',
        'metadata' => 'array',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function customerEquipment(): BelongsTo
    {
        return $this->belongsTo(CustomerEquipment::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
