<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prospect extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'Prospect';

    protected $fillable = [
        'lead_id', 'first_name', 'last_name', 'email', 'phone', 'alt_phone',
        'address', 'town', 'county', 'gps_lat', 'gps_lng',
        'interested_package', 'installation_type', 'installation_feasible',
        'feasibility_notes', 'installation_fee_quoted',
        'pipeline_stage', 'status', 'notes', 'lost_reason', 'assigned_to',
        'converted_at', 'converted_to_client_id',
    ];

    protected $casts = [
        'gps_lat' => 'decimal:7',
        'gps_lng' => 'decimal:7',
        'installation_fee_quoted' => 'decimal:2',
        'installation_feasible' => 'boolean',
        'converted_at' => 'datetime',
    ];

    public const PIPELINE_STAGES = ['new', 'negotiation', 'survey_scheduled', 'survey_completed', 'installation_scheduled', 'won', 'lost'];
    public const STATUSES = ['active', 'converted', 'lost'];
    public const INSTALLATION_TYPES = ['fiber', 'wireless', 'pppoe'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedToClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_to_client_id');
    }

    public function addresses()
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
