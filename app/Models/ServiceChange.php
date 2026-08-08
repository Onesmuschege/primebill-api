<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class ServiceChange extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_account_id',
        'type',
        'from_plan_id',
        'to_plan_id',
        'from_speed_download',
        'from_speed_upload',
        'to_speed_download',
        'to_speed_upload',
        'from_service_type',
        'to_service_type',
        'from_config',
        'to_config',
        'reason',
        'status',
        'requested_at',
        'scheduled_at',
        'completed_at',
        'requested_by',
        'approved_by',
        'work_order_id',
        'metadata',
    ];

    protected $casts = [
        'from_speed_download' => 'decimal:2',
        'from_speed_upload' => 'decimal:2',
        'to_speed_download' => 'decimal:2',
        'to_speed_upload' => 'decimal:2',
        'from_config' => 'array',
        'to_config' => 'array',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isUpgrade(): bool
    {
        return $this->type === 'upgrade';
    }

    public function isDowngrade(): bool
    {
        return $this->type === 'downgrade';
    }
}
