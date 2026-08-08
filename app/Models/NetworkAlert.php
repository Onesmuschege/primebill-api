<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;

class NetworkAlert extends Model
{
    use HasFactory, BelongsToTenant, LogsAudit;

    protected string $auditAlias = 'NetworkAlert';

    protected $fillable = [
        'device_id', 'alert_type', 'severity', 'message',
        'status', 'metric_value', 'threshold', 'interface',
        'acknowledged_at', 'acknowledged_by', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'metric_value'    => 'decimal:2',
        'threshold'       => 'decimal:2',
        'acknowledged_at' => 'datetime',
        'resolved_at'     => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Router::class, 'device_id');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
