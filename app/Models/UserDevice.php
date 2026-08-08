<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class UserDevice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'device_type',
        'device_name',
        'platform',
        'browser',
        'ip_address',
        'location',
        'is_trusted',
        'last_used_at',
        'metadata',
    ];

    protected $casts = [
        'is_trusted' => 'boolean',
        'last_used_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTrusted(): bool
    {
        return $this->is_trusted;
    }

    public function isMobile(): bool
    {
        return stripos($this->device_type ?? '', 'mobile') !== false;
    }
}
