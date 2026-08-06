<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class CustomerSubscription extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'product_id',
        'plan_id',
        'name',
        'status',
        'type',
        'price',
        'discount',
        'tax',
        'total',
        'starts_at',
        'ends_at',
        'activated_at',
        'suspended_at',
        'cancelled_at',
        'completed_at',
        'contract_period_months',
        'auto_renew',
        'prorated',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'activated_at' => 'datetime',
        'suspended_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'auto_renew' => 'boolean',
        'prorated' => 'boolean',
        'metadata' => 'array',
    ];

    // Relationships
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    // Helpers
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getRemainingDaysAttribute(): ?int
    {
        if (!$this->ends_at) return null;
        return now()->diffInDays($this->ends_at, false);
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        $remaining = $this->remaining_days;
        return $remaining !== null && $remaining > 0 && $remaining <= 7;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->ends_at && $this->ends_at->isPast();
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'active' => 'green',
            'pending' => 'yellow',
            'suspended' => 'red',
            'cancelled' => 'gray',
            'expired' => 'gray',
            'completed' => 'blue',
            default => 'gray',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'new' => 'New',
            'upgrade' => 'Upgrade',
            'downgrade' => 'Downgrade',
            'renewal' => 'Renewal',
            'addon' => 'Add-on',
            default => ucfirst($this->type),
        };
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeExpiringSoon($query)
    {
        return $query->where('ends_at', '<=', now()->addDays(7))
            ->where('ends_at', '>', now())
            ->where('status', 'active');
    }
}
