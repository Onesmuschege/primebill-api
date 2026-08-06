<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'billing_cycle',
        'price',
        'annual_price',
        'is_active',
        'is_trial_available',
        'trial_days',
        'grace_days',
        'features',
        'max_clients',
        'max_users',
        'max_routers',
        'storage_quota_gb',
        'api_calls_per_month',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'is_trial_available' => 'boolean',
        'price' => 'decimal:2',
        'annual_price' => 'decimal:2',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function getEffectivePrice(): float
    {
        if ($this->billing_cycle === 'annual' && $this->annual_price) {
            return $this->annual_price;
        }

        return $this->price;
    }
}
