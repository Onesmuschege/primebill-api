<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class TaxRate extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'rate',
        'type',
        'is_active',
        'is_default',
        'description',
    ];

    protected $casts = [
        'rate' => 'decimal:3',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function taxLines(): HasMany
    {
        return $this->hasMany(InvoiceTaxLine::class);
    }
}
