<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Traits\LogsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientDocument extends Model
{
    use HasFactory, BelongsToTenant, SoftDeletes, LogsAudit;

    protected string $auditAlias = 'ClientDocument';

    protected $fillable = [
        'client_id', 'type', 'label', 'file_path', 'file_name',
        'mime_type', 'file_size', 'description', 'expiry_date',
        'is_verified', 'verified_at', 'verified_by', 'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'expiry_date' => 'date',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public const TYPES = [
        'id_card', 'passport', 'kra_pin', 'contract', 'installation_form',
        'receipt', 'invoice_copy', 'survey_form', 'other',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now(), now()->addDays($days)]);
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')->where('expiry_date', '<', now());
    }

    public function scopeUnverified($query)
    {
        return $query->where('is_verified', false);
    }
}
