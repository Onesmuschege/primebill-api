<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class Webhook extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'url',
        'method',
        'headers',
        'authentication',
        'payload_template',
        'events',
        'status',
        'timeout',
        'retry_count',
        'retry_delay',
        'failure_threshold',
        'consecutive_failures',
        'last_success_at',
        'last_failure_at',
        'last_error',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'headers' => 'array',
        'authentication' => 'array',
        'payload_template' => 'array',
        'events' => 'array',
        'metadata' => 'array',
        'timeout' => 'integer',
        'retry_count' => 'integer',
        'retry_delay' => 'integer',
        'failure_threshold' => 'integer',
        'consecutive_failures' => 'integer',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function hasReachedFailureThreshold(): bool
    {
        return $this->consecutive_failures >= $this->failure_threshold;
    }
}
