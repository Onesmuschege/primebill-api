<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class CustomerFeedback extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'type',
        'category',
        'rating',
        'subject',
        'feedback',
        'responses',
        'attachments',
        'status',
        'response',
        'responded_by',
        'responded_at',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'responses' => 'array',
        'attachments' => 'array',
        'metadata' => 'array',
        'rating' => 'integer',
        'responded_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }
}
