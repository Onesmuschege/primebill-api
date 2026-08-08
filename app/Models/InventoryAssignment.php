<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class InventoryAssignment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'assigned_to_type',
        'assigned_to_id',
        'status',
        'assigned_date',
        'returned_date',
        'notes',
        'metadata',
        'assigned_by',
        'returned_by',
    ];

    protected $casts = [
        'assigned_date' => 'date',
        'returned_date' => 'date',
        'metadata' => 'array',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function assignedTo()
    {
        return $this->morphTo();
    }
}
