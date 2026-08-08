<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class ServiceRelocation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'client_account_id',
        'old_address_id',
        'new_address_id',
        'old_location_details',
        'new_location_details',
        'requested_date',
        'scheduled_date',
        'completed_date',
        'work_order_id',
        'technician_id',
        'status',
        'cost',
        'approval_notes',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'requested_date' => 'datetime',
        'scheduled_date' => 'datetime',
        'completed_date' => 'datetime',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    public function oldAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'old_address_id');
    }

    public function newAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'new_address_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
