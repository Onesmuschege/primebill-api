<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class TicketEscalation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'ticket_id',
        'sla_policy_id',
        'escalated_to',
        'escalation_level',
        'trigger',
        'reason',
        'resolution',
        'resolved_at',
        'resolved_by',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'escalation_level' => 'integer',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
