<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Line item on a PlatformInvoice (PrimeBill → tenant ISP). Mirrors the
 * tenant-side invoice-item convention used by the ISP billing engine.
 */
class PlatformInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_invoice_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'platform_invoice_id');
    }
}
