<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class CommunicationLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'communication_template_id',
        'channel',
        'recipient_type',
        'recipient_id',
        'recipient_address',
        'status',
        'subject',
        'content',
        'provider',
        'provider_reference',
        'error_message',
        'retry_count',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'retry_count' => 'integer',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSent(): bool
    {
        return in_array($this->status, ['sent', 'delivered']);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
