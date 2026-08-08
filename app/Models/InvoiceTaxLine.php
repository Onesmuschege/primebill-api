<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class InvoiceTaxLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'tax_rate_id',
        'tax_name',
        'tax_code',
        'rate',
        'base_amount',
        'tax_amount',
    ];

    protected $casts = [
        'rate' => 'decimal:3',
        'base_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
