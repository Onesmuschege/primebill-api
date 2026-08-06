<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'Lead';

    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'alt_phone',
        'address', 'town', 'county', 'gps_lat', 'gps_lng',
        'source', 'status', 'interest_plan', 'notes', 'lost_reason',
        'assigned_to',
        'contacted_at', 'qualified_at', 'converted_at', 'converted_to_client_id',
    ];

    protected $casts = [
        'gps_lat' => 'decimal:7',
        'gps_lng' => 'decimal:7',
        'contacted_at' => 'datetime',
        'qualified_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    public const SOURCES = ['walk_in', 'referral', 'social_media', 'website', 'call', 'sms', 'other'];
    public const STATUSES = ['new', 'contacted', 'qualified', 'survey_required', 'converted', 'lost'];

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedToClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_to_client_id');
    }

    public function prospect()
    {
        return $this->hasOne(Prospect::class);
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
