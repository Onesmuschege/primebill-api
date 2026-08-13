<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class NetworkIncident extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'NetworkIncident';

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'severity',
        'status',
        'detected_at',
        'acknowledged_at',
        'resolved_at',
        'closed_at',
        'affected_device_id',
        'affected_olt_id',
        'affected_pon_port_id',
        'created_by',
        'assigned_to',
        'acknowledged_by',
        'resolved_by',
        'root_cause',
        'resolution',
        'affected_services',
        'affected_customers_count',
        'duration_minutes',
        'escalation_level',
        'escalated_at',
        'escalated_by',
        'escalation_reason',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'escalation_level' => 'integer',
        'affected_services' => 'array',
        'affected_customers_count' => 'integer',
        'duration_minutes' => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────────────────────

    public function affectedDevice()
    {
        return $this->belongsTo(Router::class, 'affected_device_id');
    }

    public function affectedOlt()
    {
        return $this->belongsTo(Olt::class, 'affected_olt_id');
    }

    public function affectedPonPort()
    {
        return $this->belongsTo(PonPort::class, 'affected_pon_port_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedTechnician()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function acknowledgedByUser()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedByUser()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function escalatedByUser()
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['detected', 'acknowledged', 'investigating', 'mitigating']);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    // ─── Lifecycle helpers ───────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, ['detected', 'acknowledged', 'investigating', 'mitigating'], true);
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Allowed state transitions.
     */
    public function allowedTransitions(): array
    {
        return match ($this->status) {
            'detected'     => ['acknowledged', 'investigating', 'resolved', 'closed'],
            'acknowledged' => ['investigating', 'mitigating', 'resolved', 'closed'],
            'investigating'=> ['mitigating', 'resolved', 'closed'],
            'mitigating'   => ['resolved', 'closed'],
            'resolved'     => ['closed'],
            'closed'       => [],
            default        => [],
        };
    }

    /**
     * Transition to a new status if allowed.
     */
    public function transitionTo(string $newStatus, ?string $reason = null): bool
    {
        $allowed = $this->allowedTransitions();

        if (!in_array($newStatus, $allowed, true)) {
            return false;
        }

        $oldStatus = $this->status;
        $this->status = $newStatus;

        $now = now();

        match ($newStatus) {
            'acknowledged' => $this->acknowledged_at = $now,
            'resolved'     => $this->resolved_at = $now,
            'closed'       => $this->closed_at = $now,
            default        => null,
        };

        $this->save();

        $this->logAudit('status_changed', [
            'status' => $oldStatus,
        ], [
            'status' => $newStatus,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Escalate an open incident. Escalation is orthogonal to the lifecycle
     * state machine: it raises the escalation level (0 → 1 → 2 → 3, capped),
     * optionally bumps severity, and records who/when/why. Closed incidents
     * cannot be escalated.
     */
    public function escalate(int $userId, ?string $reason = null, ?string $severity = null): bool
    {
        if ($this->isClosed() || $this->isResolved()) {
            return false;
        }

        $this->escalation_level = min($this->escalation_level + 1, 3);
        $this->escalated_at = now();
        $this->escalated_by = $userId;
        if ($reason !== null) {
            $this->escalation_reason = $reason;
        }
        if ($severity !== null && in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $this->severity = $severity;
        }

        $this->save();

        $this->logAudit('escalated', [
            'escalation_level' => $this->escalation_level,
        ], [
            'reason'   => $reason,
            'severity' => $this->severity,
        ]);

        return true;
    }

    public function escalationLevel(): int
    {
        return $this->escalation_level;
    }
}
