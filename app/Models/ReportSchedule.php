<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class ReportSchedule extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'saved_report_id',
        'name',
        'frequency',
        'format',
        'recipients',
        'last_sent_at',
        'next_send_at',
        'is_active',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'recipients' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'last_sent_at' => 'datetime',
        'next_send_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(SavedReport::class, 'saved_report_id');
    }

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
        return $this->is_active;
    }

    public function isDue(): bool
    {
        return $this->is_active && $this->next_send_at && $this->next_send_at->isPast();
    }
}
