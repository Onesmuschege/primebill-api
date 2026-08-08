<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class Promotion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'min_subtotal',
        'max_discount',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function isActive(): bool
    {
        if (!$this->is_active) return false;

        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;

        return true;
    }
}
