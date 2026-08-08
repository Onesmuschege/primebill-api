<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class WebhookDelivery extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'webhook_id',
        'event',
        'status',
        'attempt_number',
        'request_payload',
        'response_body',
        'response_status',
        'duration_ms',
        'error_message',
        'next_retry_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'attempt_number' => 'integer',
        'duration_ms' => 'integer',
        'next_retry_at' => 'datetime',
    ];

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function shouldRetry(): bool
    {
        return $this->status === 'failed' && $this->attempt_number < 3;
    }
}
