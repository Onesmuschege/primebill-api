<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class Coupon extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'promotion_id',
        'code',
        'type',
        'value',
        'min_subtotal',
        'max_discount',
        'usage_limit',
        'usage_count',
        'per_client_limit',
        'is_active',
        'starts_at',
        'expires_at',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_subtotal' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'usage_count' => 'integer',
        'per_client_limit' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        if (!$this->is_active) return false;
        if ($this->isExpired()) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->usage_limit !== null && $this->usage_count >= $this->usage_limit) return false;

        return true;
    }

    public function canBeUsedBy(int $clientId): bool
    {
        if (!$this->isActive()) return false;

        if ($this->per_client_limit !== null) {
            $used = $this->redemptions()
                ->where('client_id', $clientId)
                ->count();

            if ($used >= $this->per_client_limit) return false;
        }

        return true;
    }
}
