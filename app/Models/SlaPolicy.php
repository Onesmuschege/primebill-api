<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class SlaPolicy extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'department_id',
        'ticket_queue_id',
        'ticket_category_id',
        'name',
        'code',
        'description',
        'priority_level',
        'response_time_minutes',
        'resolution_time_minutes',
        'business_hours',
        'apply_on_weekends',
        'apply_on_holidays',
        'escalation_enabled',
        'escalation_after_minutes',
        'is_active',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'priority_level' => 'integer',
        'response_time_minutes' => 'integer',
        'resolution_time_minutes' => 'integer',
        'business_hours' => 'array',
        'apply_on_weekends' => 'boolean',
        'apply_on_holidays' => 'boolean',
        'escalation_enabled' => 'boolean',
        'escalation_after_minutes' => 'integer',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function ticketQueue(): BelongsTo
    {
        return $this->belongsTo(TicketQueue::class);
    }

    public function ticketCategory(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(TicketEscalation::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
