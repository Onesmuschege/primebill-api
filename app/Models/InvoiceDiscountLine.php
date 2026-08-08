<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class InvoiceDiscountLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'discount_id',
        'coupon_id',
        'discount_name',
        'type',
        'value',
        'base_amount',
        'discount_amount',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
