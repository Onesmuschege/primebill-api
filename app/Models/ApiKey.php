<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class ApiKey extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'name',
        'key_id',
        'key_hash',
        'key_secret',
        'last_used_ip',
        'last_used_user_agent',
        'last_used_at',
        'expires_at',
        'revoked',
        'scopes',
    ];

    protected function casts(): array
    {
        return [
            'revoked' => 'boolean',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if the key is valid (not revoked and not expired).
     */
    public function isValid(): bool
    {
        if ($this->revoked) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Update last used information.
     */
    public function updateLastUsed(string $ip, ?string $userAgent): void
    {
        $this->update([
            'last_used_ip' => $ip,
            'last_used_user_agent' => $userAgent,
            'last_used_at' => now(),
        ]);
    }
}
