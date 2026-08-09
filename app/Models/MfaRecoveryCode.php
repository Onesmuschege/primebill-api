<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class MfaRecoveryCode extends Model
{
    use BelongsToTenant;

    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'code_hash',
        'mfa_credential_id',
        'used',
        'used_at',
        'expires_at',
        'attempts',
        'last_attempt_at',
        'metadata',
    ];

    protected $casts = [
        'used' => 'boolean',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'attempts' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isLocked(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function isValid(): bool
    {
        return !$this->used && !$this->isExpired() && !$this->isLocked();
    }

    /**
     * Record a failed verification attempt. Once MAX_ATTEMPTS is reached the
     * code is permanently locked out (defence against brute-force).
     */
    public function recordFailedAttempt(): void
    {
        $this->increment('attempts');
        $this->last_attempt_at = now();
        $this->save();
    }

    /**
     * Mark the code as consumed (single-use).
     */
    public function consume(): void
    {
        $this->used = true;
        $this->used_at = now();
        $this->save();
    }
}
