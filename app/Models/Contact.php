<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'Contact';

    protected $fillable = [
        'client_id', 'first_name', 'last_name', 'email', 'phone', 'alt_phone',
        'relationship', 'type', 'is_primary', 'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public const TYPES = ['billing', 'technical', 'emergency', 'general'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
