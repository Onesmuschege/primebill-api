<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class PurchaseOrder extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'warehouse_id',
        'po_number',
        'status',
        'order_date',
        'expected_delivery',
        'received_date',
        'subtotal',
        'tax_amount',
        'total_amount',
        'notes',
        'metadata',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery' => 'date',
        'received_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
