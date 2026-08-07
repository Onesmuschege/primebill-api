<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'WorkOrder';

    protected $fillable = [
        'tenant_id',
        'work_order_number',
        'client_id',
        'type',
        'status',
        'priority',
        'description',
        'notes',
        'scheduled_at',
        'started_at',
        'completed_at',
        'assigned_to',
        'created_by',
        'photos',
        'customer_signature',
        'completion_notes',
        'completion_latitude',
        'completion_longitude',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'photos' => 'array',
        'customer_signature' => 'array',
        'completion_latitude' => 'decimal:8',
        'completion_longitude' => 'decimal:8',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedTechnician()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
