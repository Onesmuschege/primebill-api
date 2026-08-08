<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class Announcement extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'title',
        'type',
        'summary',
        'content',
        'priority',
        'target_audience',
        'starts_at',
        'ends_at',
        'is_published',
        'send_notification',
        'attachments',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_audience' => 'array',
        'attachments' => 'array',
        'metadata' => 'array',
        'is_published' => 'boolean',
        'send_notification' => 'boolean',
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isActive(): bool
    {
        if (!$this->is_published) return false;

        $now = now()->toDateString();
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;

        return true;
    }
}
