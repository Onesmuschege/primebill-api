<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToTenant;

class RadiusDisconnectRequest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'radius_session_id',
        'client_id',
        'router_id',
        'reason',
        'reason_details',
        'status',
        'response',
        'error_message',
        'duration_ms',
        'metadata',
        'created_by',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'metadata' => 'array',
        'duration_ms' => 'integer',
    ];

    public function radiusSession(): BelongsTo
    {
        return $this->belongsTo(RadiusSession::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
