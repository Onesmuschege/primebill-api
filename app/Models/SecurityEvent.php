<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class SecurityEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'event',
        'severity',
        'category',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
        'source',
        'is_resolved',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->is_resolved;
    }

    public function isCritical(): bool
    {
        return $this->severity === 'critical';
    }
}
