<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToTenant;

class TicketQueue extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'department_id',
        'name',
        'code',
        'description',
        'status',
        'priority',
        'assigned_to',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'priority' => 'integer',
        'metadata' => 'array',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
