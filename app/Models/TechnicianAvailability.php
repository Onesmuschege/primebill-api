<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class TechnicianAvailability extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'available_date',
        'available_from',
        'available_to',
        'status',
        'type',
        'max_jobs',
        'assigned_jobs',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'available_date' => 'date',
        'available_from' => 'time',
        'available_to' => 'time',
        'max_jobs' => 'integer',
        'assigned_jobs' => 'integer',
        'metadata' => 'array',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function hasCapacity(): bool
    {
        return $this->max_jobs === null || $this->assigned_jobs < $this->max_jobs;
    }
}
