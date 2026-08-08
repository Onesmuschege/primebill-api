<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RouterCommandLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'router_id',
        'command',
        'source',
        'status',
        'result',
        'error_message',
        'duration_ms',
        'context',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'context' => 'array',
        'duration_ms' => 'integer',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
