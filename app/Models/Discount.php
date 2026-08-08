<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class Discount extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'type',
        'value',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function discountLines(): HasMany
    {
        return $this->hasMany(InvoiceDiscountLine::class);
    }

    public function isActive(): bool
    {
        if (!$this->is_active) return false;

        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;

        return true;
    }
}
