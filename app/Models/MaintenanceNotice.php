<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class MaintenanceNotice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'title',
        'type',
        'summary',
        'description',
        'impact_description',
        'affected_services',
        'affected_areas',
        'severity',
        'starts_at',
        'ends_at',
        'completed_at',
        'is_published',
        'send_notification',
        'notifications_sent',
        'attachments',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'affected_services' => 'array',
        'affected_areas' => 'array',
        'notifications_sent' => 'array',
        'attachments' => 'array',
        'metadata' => 'array',
        'is_published' => 'boolean',
        'send_notification' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'completed_at' => 'datetime',
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

        $now = now();
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->ends_at && $this->ends_at->isPast()) return false;

        return true;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
