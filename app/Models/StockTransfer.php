<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class StockTransfer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'source_warehouse_id',
        'destination_warehouse_id',
        'reference_number',
        'status',
        'expected_date',
        'completed_date',
        'notes',
        'metadata',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'expected_date' => 'date',
        'completed_date' => 'date',
        'metadata' => 'array',
    ];

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
